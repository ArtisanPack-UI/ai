<?php

/**
 * ArtisanPack AI agent base class.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ai\Agents;

use ArtisanPackUI\Ai\Contracts\CredentialResolver;
use ArtisanPackUI\Ai\Contracts\FeatureRegistry;
use ArtisanPackUI\Ai\Credentials\Credentials;
use ArtisanPackUI\Ai\Events\AgentUsageRecorded;
use ArtisanPackUI\Ai\Exceptions\FeatureDisabledException;
use ArtisanPackUI\Ai\Exceptions\MissingCredentialsException;
use ArtisanPackUI\Ai\Support\FeatureSettings;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use InvalidArgumentException;
use LogicException;
use Stringable;

/**
 * Thin wrapper agents in every ArtisanPack UI package extend.
 *
 * The public surface below is **frozen for v1.x** and must not change
 * without a major version bump:
 *
 *   - Public properties: `$featureKey`, `$package`, `$defaultModel`
 *   - Abstract methods: `instructions()`, `outputSchema()`
 *   - Static factory: `self::for( $input )`
 *   - Public entry point: `run(): array`
 *   - Fluent overrides: `withCredentials()`, `withModel()`, `withStreaming()`
 *   - Cache-key hook: `cacheFingerprint()`
 *
 * Subclasses implement `instructions()` and `outputSchema()` and, if the
 * default `execute()` isn't sufficient, override `execute()` to talk to
 * `laravel/ai` directly. Downstream agents that want provider failover,
 * broadcast/queue dispatch, and `Ai::fake()` should also `use \Laravel\Ai\Promptable;`
 * and call `$this->prompt(...)` / `$this->stream(...)` from their `execute()`.
 * The fluent toggle is named `withStreaming()` (not `stream()`) so it never
 * collides with the trait method.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */
abstract class ArtisanPackAgent
{
    /**
     * Fully-qualified feature key (dot notation, e.g.
     * `seo.suggest_meta_description`).
     *
     * @since 1.0.0
     *
     * @var string
     */
    public string $featureKey = '';

    /**
     * Owning composer package name (e.g. `artisanpack-ui/seo`).
     *
     * @since 1.0.0
     *
     * @var string
     */
    public string $package = '';

    /**
     * Fallback model used when no per-feature or runtime model is set.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public string $defaultModel = 'haiku';

    /**
     * Whether this agent's responses are cacheable.
     *
     * Subclasses can opt out of the read-through cache by setting this to
     * `false`. Combined with the `artisanpack.ai.cache.enabled` config flag,
     * both must be truthy for cache reads/writes to happen.
     *
     * @since 1.0.0
     *
     * @var bool
     */
    public bool $cacheable = true;

    /**
     * Per-agent cache TTL override in seconds.
     *
     * Zero (the default) means: defer to `artisanpack.ai.cache.ttl`.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public int $cacheTtl = 0;

    /**
     * Whether streaming is the default execution mode for this agent.
     *
     * The RFC calls for streaming on by default for long-running agents
     * (content analysis, summaries, digests). Subclasses can flip this to
     * `false` to force the non-streaming path.
     *
     * @since 1.0.0
     *
     * @var bool
     */
    public bool $stream = false;

    /**
     * Input payload the agent will operate on.
     *
     * @since 1.0.0
     *
     * @var mixed
     */
    protected mixed $input = null;

    /**
     * Optional runtime credential override.
     *
     * @since 1.0.0
     *
     * @var Credentials|null
     */
    protected ?Credentials $credentialOverride = null;

    /**
     * Optional runtime model override.
     *
     * @since 1.0.0
     *
     * @var string|null
     */
    protected ?string $modelOverride = null;

    /**
     * Whether the run should stream chunks instead of returning a full
     * result.
     *
     * @since 1.0.0
     *
     * @var bool
     */
    protected bool $streaming = false;

    /**
     * Optional per-run chunk callback used when streaming.
     *
     * When set, subclasses that stream should invoke this callback with each
     * emitted chunk (see `emitChunk()`).
     *
     * @since 1.0.0
     *
     * @var callable|null
     */
    protected $streamCallback = null;

    /**
     * System prompt/instructions the agent should follow.
     *
     * @since 1.0.0
     *
     * @return string
     */
    abstract public function instructions(): string;

    /**
     * JSON-Schema-style array describing the structured output.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    abstract public function outputSchema(): array;

    /**
     * Build a new agent instance ready to run against `$input`.
     *
     * @since 1.0.0
     *
     * @param  mixed  $input  Domain input the agent will consume.
     *
     * @return static
     */
    public static function for( mixed $input ): static
    {
        /** @var static $agent */
        $agent = app( static::class );

        // Reset every run-scoped field so a container-singleton binding
        // (documented in docs/overriding-agents.md) or an Octane-cached
        // instance can't leak run N-1's callback, credentials, or model
        // override into run N.
        $agent->input              = $input;
        $agent->streaming          = $agent->stream;
        $agent->streamCallback     = null;
        $agent->credentialOverride = null;
        $agent->modelOverride      = null;

        return $agent;
    }

    /**
     * Set a runtime credential override (highest precedence).
     *
     * @since 1.0.0
     *
     * @param  Credentials  $credentials  Credentials to use for this run.
     *
     * @return static
     */
    public function withCredentials( Credentials $credentials ): static
    {
        $this->credentialOverride = $credentials;

        return $this;
    }

    /**
     * Set a runtime model override.
     *
     * @since 1.0.0
     *
     * @param  string  $model  Model identifier (e.g. `haiku`, `gpt-4o`).
     *
     * @return static
     */
    public function withModel( string $model ): static
    {
        $this->modelOverride = $model;

        return $this;
    }

    /**
     * Toggle streaming mode for the next run.
     *
     * Named `withStreaming()` to avoid colliding with
     * `\Laravel\Ai\Promptable::stream()` which subclasses may pull in.
     *
     * @since 1.0.0
     *
     * @return static
     */
    public function withStreaming(): static
    {
        $this->streaming = true;

        return $this;
    }

    /**
     * Determine whether streaming was requested for the next run.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public function isStreaming(): bool
    {
        return $this->streaming;
    }

    /**
     * Register a per-chunk callback and enable streaming for this run.
     *
     * The callback receives the current chunk (string) plus the accumulated
     * text so far. Registering a callback also implies `->withStreaming()`.
     *
     * @since 1.0.0
     *
     * @param  callable  $onChunk  `function ( string $chunk, string $accumulated ): void`.
     *
     * @return static
     */
    public function streamTo( callable $onChunk ): static
    {
        $this->streamCallback = $onChunk;
        $this->streaming      = true;

        return $this;
    }

    /**
     * Return the currently registered stream callback, if any.
     *
     * Exposed so transport wrappers (e.g. `AgentStreamResponse`) can
     * compose with a caller-supplied callback instead of silently
     * overwriting it.
     *
     * @since 1.0.0
     *
     * @return callable|null
     */
    public function streamCallback(): ?callable
    {
        return $this->streamCallback;
    }

    /**
     * Execute the agent and return validated output.
     *
     * The default pipeline is:
     *
     *   1. Reject if the feature is disabled.
     *   2. Resolve credentials, rejecting when none are configured.
     *   3. Resolve model (runtime → per-feature config → `$defaultModel`).
     *   4. Serve from cache if `cache.enabled` and a hit exists.
     *   5. Delegate to `execute()` and validate the shape.
     *   6. Dispatch `AgentUsageRecorded` with token telemetry.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function run(): array
    {
        /** @var Container $container */
        $container = app();

        /** @var FeatureRegistry $registry */
        $registry = $container->make( FeatureRegistry::class );

        if ( '' !== $this->featureKey
            && null !== $registry->get( $this->featureKey )
            && ! $registry->isToggleOn( $this->featureKey )
        ) {
            throw FeatureDisabledException::forFeature( $this->featureKey );
        }

        $credentials = $this->resolveCredentials( $container );

        if ( ! $credentials instanceof Credentials ) {
            throw MissingCredentialsException::forFeature( $this->featureKey );
        }

        $model = $this->resolveModel( $container, $credentials );

        // Only skip cache when the caller is actively consuming a stream
        // (has registered a chunk callback). A $stream=true agent invoked
        // from a batch/warm-up path with no callback should still enjoy
        // cache hits — the RFC intent is that cached responses return
        // whole, not that streaming disables caching altogether.
        $isConsumingStream = $this->streaming && null !== $this->streamCallback;
        $cache             = ( $isConsumingStream || ! $this->cacheable )
            ? null
            : $this->cacheStore( $container );

        if ( null !== $cache ) {
            $cacheKey = $this->cacheKey( $model );
            $cached   = $cache->get( $cacheKey );

            if ( is_array( $cached ) ) {
                $this->recordUsage( $container, $model, 0, 0, true, $credentials );

                return $cached;
            }
        }

        $result = $this->execute( $credentials, $model );

        if ( null !== $cache ) {
            $cache->put( $cacheKey, $result['output'], $this->resolveCacheTtl( $container ) );
        }

        $this->recordUsage(
            $container,
            $model,
            (int) ( $result['input_tokens'] ?? 0 ),
            (int) ( $result['output_tokens'] ?? 0 ),
            false,
            $credentials,
        );

        return $result['output'];
    }

    /**
     * Resolve the instructions the agent should use for its next run.
     *
     * Precedence:
     *
     *   1. Settings-backed override (`FeatureSettings::instructions()`) —
     *      the "config layer that survives request boundaries" the RFC
     *      calls for.
     *   2. `artisanpack.ai.features.{key}.instructions` config value.
     *   3. Class-level default returned by `instructions()`.
     *
     * `instructions()` remains part of the frozen v1.x public surface —
     * subclasses continue to override it to define their default prompt.
     * Executors that want the *effective* prompt should call this method
     * instead of `instructions()` directly.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function resolvedInstructions(): string
    {
        /** @var Container $container */
        $container = app();

        $fromSettings = $this->featureSettingsValue( $container, 'instructions' );

        if ( null !== $fromSettings ) {
            return $fromSettings;
        }

        $featureConfig = $this->featureConfig( $container );

        if (
            isset( $featureConfig['instructions'] )
            && is_string( $featureConfig['instructions'] )
            && '' !== $featureConfig['instructions']
        ) {
            return $featureConfig['instructions'];
        }

        return $this->instructions();
    }

    /**
     * Emit a chunk through the registered stream callback, if any.
     *
     * Subclasses implementing streaming inside `execute()` should call this
     * for each token/segment they receive from the provider. Returns the
     * accumulated text so callers don't need to track it themselves.
     *
     * @since 1.0.0
     *
     * @param  string  $chunk        Chunk text.
     * @param  string  $accumulated  Accumulated text so far (post-chunk).
     *
     * @return void
     */
    protected function emitChunk( string $chunk, string $accumulated ): void
    {
        if ( null === $this->streamCallback ) {
            return;
        }

        ( $this->streamCallback )( $chunk, $accumulated );
    }

    /**
     * Perform the actual model call.
     *
     * The default implementation raises a runtime exception; subclasses are
     * expected to override with a call into `laravel/ai`.
     *
     * The return array must include:
     *   - `output`        : `array<string, mixed>` shaped like `outputSchema()`
     *   - `input_tokens`  : `int`
     *   - `output_tokens` : `int`
     *
     * @since 1.0.0
     *
     * @param  Credentials  $credentials  Resolved credentials.
     * @param  string       $model        Resolved model identifier.
     *
     * @return array{ output: array<string, mixed>, input_tokens: int, output_tokens: int }
     */
    protected function execute( Credentials $credentials, string $model ): array
    {
        throw new LogicException(
            sprintf( 'Agent %s must override execute() to talk to laravel/ai.', static::class ),
        );
    }

    /**
     * Accessor for the input payload.
     *
     * @since 1.0.0
     *
     * @return mixed
     */
    protected function input(): mixed
    {
        return $this->input;
    }

    /**
     * Resolve credentials against runtime override → resolver → null.
     *
     * @since 1.0.0
     *
     * @param  Container  $container  Service container.
     *
     * @return Credentials|null
     */
    protected function resolveCredentials( Container $container ): ?Credentials
    {
        if ( $this->credentialOverride instanceof Credentials ) {
            return $this->credentialOverride;
        }

        /** @var CredentialResolver $resolver */
        $resolver = $container->make( CredentialResolver::class );

        return $resolver->forFeature( $this->featureKey );
    }

    /**
     * Resolve the model against runtime override → settings-backed override
     * → per-feature config → per-feature credentials → class default.
     *
     * Reads the `artisanpack.ai.features` array with a literal-key lookup
     * so that dot-notation feature keys (e.g. `seo.suggest_meta_description`)
     * are not mis-parsed as nested config paths.
     *
     * @since 1.0.0
     *
     * @param  Container    $container    Service container.
     * @param  Credentials  $credentials  Resolved credentials (contain any
     *                                    per-feature model override).
     *
     * @return string
     */
    protected function resolveModel( Container $container, Credentials $credentials ): string
    {
        if ( null !== $this->modelOverride ) {
            return $this->modelOverride;
        }

        $fromSettings = $this->featureSettingsValue( $container, 'model' );

        if ( null !== $fromSettings ) {
            return $fromSettings;
        }

        $featureConfig = $this->featureConfig( $container );

        if ( isset( $featureConfig['model'] ) && is_string( $featureConfig['model'] ) && '' !== $featureConfig['model'] ) {
            return $featureConfig['model'];
        }

        if ( null !== $credentials->defaultModel && '' !== $credentials->defaultModel ) {
            return $credentials->defaultModel;
        }

        return $this->defaultModel;
    }

    /**
     * Read a settings-backed override for the current feature.
     *
     * Returns null when no `FeatureSettings` binding is available, no feature
     * key is set, or nothing is stored for the requested field.
     *
     * @since 1.0.0
     *
     * @param  Container  $container  Service container.
     * @param  string     $field      `model` or `instructions`.
     *
     * @return string|null
     */
    protected function featureSettingsValue( Container $container, string $field ): ?string
    {
        if ( '' === $this->featureKey ) {
            return null;
        }

        if ( ! $container->bound( FeatureSettings::class ) ) {
            return null;
        }

        /** @var FeatureSettings $settings */
        $settings = $container->make( FeatureSettings::class );

        $value = 'model' === $field
            ? $settings->model( $this->featureKey )
            : $settings->instructions( $this->featureKey );

        return null === $value || '' === $value ? null : $value;
    }

    /**
     * Read this feature's config entry via literal-key lookup on the
     * `artisanpack.ai.features` array.
     *
     * @since 1.0.0
     *
     * @param  Container  $container  Service container.
     *
     * @return array<string, mixed>
     */
    protected function featureConfig( Container $container ): array
    {
        if ( '' === $this->featureKey ) {
            return [];
        }

        /** @var ConfigRepository $config */
        $config = $container->make( ConfigRepository::class );

        $features = $config->get( 'artisanpack.ai.features', [] );

        if ( ! is_array( $features ) || ! isset( $features[ $this->featureKey ] ) ) {
            return [];
        }

        $entry = $features[ $this->featureKey ];

        return is_array( $entry ) ? $entry : [];
    }

    /**
     * Deterministic cache key for the current input, feature, and model.
     *
     * Subclasses should override `cacheFingerprint()` to control how the
     * input is fingerprinted rather than overriding this method.
     *
     * @since 1.0.0
     *
     * @param  string  $model  Resolved model identifier.
     *
     * @return string
     */
    protected function cacheKey( string $model ): string
    {
        return 'artisanpack.ai:' . hash(
            'sha256',
            $this->featureKey . '|' . $model . '|' . $this->cacheFingerprint(),
        );
    }

    /**
     * Stable, deterministic fingerprint of the input payload.
     *
     * The default implementation only fingerprints scalars, arrays of
     * scalars, and `Stringable` values so cache keys are reproducible
     * across requests. Subclasses that accept richer inputs (Eloquent
     * models, DTOs, closures) must override this and return their own
     * stable string — e.g. `$this->input->id` or a hash of the fields
     * that matter — to avoid a serialize() over hidden model state.
     *
     * @since 1.0.0
     *
     * @throws InvalidArgumentException When the default cannot produce a stable fingerprint.
     *
     * @return string
     */
    protected function cacheFingerprint(): string
    {
        $input = $this->input;

        if ( null === $input ) {
            return 'null';
        }

        if ( is_scalar( $input ) ) {
            return gettype( $input ) . ':' . (string) $input;
        }

        if ( $input instanceof Stringable ) {
            return 'stringable:' . (string) $input;
        }

        if ( is_array( $input ) && $this->isPurelyScalarArray( $input ) ) {
            $normalised = $this->normaliseScalarArray( $input );

            return 'array:' . json_encode( $normalised, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        }

        throw new InvalidArgumentException( sprintf(
            'Cannot produce a stable cache fingerprint for input of type %s in agent %s. Override cacheFingerprint().',
            get_debug_type( $input ),
            static::class,
        ) );
    }

    /**
     * Determine whether an array contains only scalars, nulls, or nested
     * arrays with the same property.
     *
     * @since 1.0.0
     *
     * @param  array<mixed, mixed>  $value  Array to inspect.
     *
     * @return bool
     */
    protected function isPurelyScalarArray( array $value ): bool
    {
        foreach ( $value as $item ) {
            if ( null === $item || is_scalar( $item ) ) {
                continue;
            }

            if ( is_array( $item ) && $this->isPurelyScalarArray( $item ) ) {
                continue;
            }

            return false;
        }

        return true;
    }

    /**
     * Recursively sort an array by key for a stable JSON serialisation.
     *
     * @since 1.0.0
     *
     * @param  array<mixed, mixed>  $value  Array to normalise.
     *
     * @return array<mixed, mixed>
     */
    protected function normaliseScalarArray( array $value ): array
    {
        ksort( $value );

        foreach ( $value as $key => $item ) {
            if ( is_array( $item ) ) {
                $value[ $key ] = $this->normaliseScalarArray( $item );
            }
        }

        return $value;
    }

    /**
     * Cache store used for read-through, or null when disabled.
     *
     * @since 1.0.0
     *
     * @param  Container  $container  Service container.
     *
     * @return CacheRepository|null
     */
    protected function cacheStore( Container $container ): ?CacheRepository
    {
        /** @var ConfigRepository $config */
        $config = $container->make( ConfigRepository::class );

        $enabled = (bool) $config->get( 'artisanpack.ai.cache.enabled', false );

        if ( ! $enabled ) {
            return null;
        }

        $storeName = $config->get( 'artisanpack.ai.cache.store' );

        if ( is_string( $storeName ) && '' !== $storeName ) {
            /** @var \Illuminate\Contracts\Cache\Factory $factory */
            $factory = $container->make( 'cache' );

            return $factory->store( $storeName );
        }

        return $container->make( 'cache.store' );
    }

    /**
     * Effective cache TTL in seconds: per-agent override → config default.
     *
     * @since 1.0.0
     *
     * @param  Container  $container  Service container.
     *
     * @return int
     */
    protected function resolveCacheTtl( Container $container ): int
    {
        if ( $this->cacheTtl > 0 ) {
            return $this->cacheTtl;
        }

        /** @var ConfigRepository $config */
        $config = $container->make( ConfigRepository::class );

        return (int) $config->get( 'artisanpack.ai.cache.ttl', 3600 );
    }

    /**
     * Dispatch the usage-tracking event.
     *
     * `$credentials` is appended (nullable) rather than inserted in the
     * middle of the arg list so any existing subclass that still overrides
     * the pre-1.0 5-arg signature keeps type-compatible — it simply loses
     * the `provider` field on the event until it's updated.
     *
     * @since 1.0.0
     *
     * @param  Container         $container    Service container.
     * @param  string            $model        Resolved model identifier.
     * @param  int               $inputTokens  Input token count.
     * @param  int               $outputTokens Output token count.
     * @param  bool              $cacheHit     Whether the response was served from cache.
     * @param  Credentials|null  $credentials  Resolved credentials (source of `provider`).
     *
     * @return void
     */
    protected function recordUsage(
        Container $container,
        string $model,
        int $inputTokens,
        int $outputTokens,
        bool $cacheHit,
        ?Credentials $credentials = null,
    ): void {
        /** @var Dispatcher $events */
        $events = $container->make( Dispatcher::class );

        $events->dispatch( new AgentUsageRecorded(
            featureKey: $this->featureKey,
            package: $this->package,
            model: $model,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            cacheHit: $cacheHit,
            provider: null !== $credentials ? $credentials->provider : '',
        ) );
    }
}
