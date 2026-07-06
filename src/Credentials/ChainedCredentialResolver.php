<?php

/**
 * Chained credential resolver.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ai\Credentials;

use ArtisanPackUI\Ai\Contracts\CredentialResolver;
use Illuminate\Contracts\Config\Repository;

/**
 * Default credential resolver.
 *
 * Walks the frozen precedence chain and returns the first complete match:
 *
 *   1. Explicit runtime override (`setOverride()`)
 *   2. Database-backed store (typically the cms-framework Settings module,
 *      injected via `useStore()`)
 *   3. `ARTISANPACK_AI_*` env vars
 *   4. `null`
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */
class ChainedCredentialResolver implements CredentialResolver
{
    /**
     * Runtime override, if any.
     *
     * @since 1.0.0
     *
     * @var Credentials|null
     */
    protected ?Credentials $override = null;

    /**
     * Optional database-backed store.
     *
     * Signature: `fn( ?string $featureKey ): ?Credentials`.
     *
     * @since 1.0.0
     *
     * @var (callable( ?string ): ?Credentials)|null
     */
    protected $store;

    /**
     * Build the resolver.
     *
     * @since 1.0.0
     *
     * @param  Repository  $config  Config repository.
     */
    public function __construct( protected Repository $config )
    {
    }

    /**
     * Set the runtime override.
     *
     * **Warning:** the resolver is a container singleton, so this mutation
     * persists for the lifetime of the process. Under Laravel Octane or a
     * long-running queue worker, the override survives across requests /
     * jobs. Use `withOverride()` for scoped swaps that reset automatically.
     *
     * @since 1.0.0
     *
     * @param  Credentials|null  $credentials  Credentials to use, or null to clear.
     *
     * @return void
     */
    public function setOverride( ?Credentials $credentials ): void
    {
        $this->override = $credentials;
    }

    /**
     * Run a callback with the given credentials in effect, restoring the
     * previous override afterwards.
     *
     * The previous override is restored even if the callback throws.
     *
     * @since 1.0.0
     *
     * @template TReturn
     *
     * @param  Credentials|null  $credentials  Credentials to use during the callback.
     * @param  callable(): TReturn  $callback   Callback to invoke.
     *
     * @return TReturn
     */
    public function withOverride( ?Credentials $credentials, callable $callback ): mixed
    {
        $previous       = $this->override;
        $this->override = $credentials;

        try {
            return $callback();
        } finally {
            $this->override = $previous;
        }
    }

    /**
     * Bind the database-backed store.
     *
     * @since 1.0.0
     *
     * @param  (callable( ?string ): ?Credentials)  $store  Store callback.
     *
     * @return void
     */
    public function useStore( callable $store ): void
    {
        $this->store = $store;
    }

    /**
     * {@inheritDoc}
     */
    public function resolve( ?string $featureKey = null ): ?Credentials
    {
        if ( null !== $this->override ) {
            return $this->override;
        }

        if ( null !== $this->store ) {
            $fromStore = ( $this->store )( $featureKey );

            if ( $fromStore instanceof Credentials ) {
                return $fromStore;
            }
        }

        return $this->resolveFromEnv( $featureKey );
    }

    /**
     * {@inheritDoc}
     */
    public function hasAny(): bool
    {
        return null !== $this->resolve();
    }

    /**
     * {@inheritDoc}
     */
    public function forFeature( string $featureKey ): ?Credentials
    {
        return $this->resolve( $featureKey );
    }

    /**
     * Build credentials from env vars, applying per-feature overrides.
     *
     * Ollama is a special case: the daemon runs locally and does not
     * require an API key. We accept an empty `ARTISANPACK_AI_API_KEY`
     * when the resolved provider is `ollama` as long as a base URL is
     * available (env, config, or the provider's default in
     * `providers.ollama.base_url`).
     *
     * @since 1.0.0
     *
     * @param  string|null  $featureKey  Optional feature key for per-feature overrides.
     *
     * @return Credentials|null Credentials, or null when incomplete.
     */
    protected function resolveFromEnv( ?string $featureKey ): ?Credentials
    {
        $provider = $this->envOrConfig( 'ARTISANPACK_AI_PROVIDER', 'artisanpack.ai.default' );
        $apiKey   = $this->envOrConfig( 'ARTISANPACK_AI_API_KEY', 'artisanpack.ai.api_key' );

        if ( null === $provider || '' === $provider ) {
            return null;
        }

        $defaultModel = $this->envOrConfig( 'ARTISANPACK_AI_DEFAULT_MODEL', 'artisanpack.ai.default_model' );
        $baseUrl      = $this->envOrConfig( 'ARTISANPACK_AI_BASE_URL', 'artisanpack.ai.base_url' );

        if ( 'ollama' === $provider ) {
            // Fall back to the per-provider defaults so a fresh install with
            // `ARTISANPACK_AI_PROVIDER=ollama` and nothing else set still
            // resolves the built-in `http://127.0.0.1:11434` URL and model.
            if ( null === $baseUrl || '' === $baseUrl ) {
                $baseUrl = $this->providerConfigString( 'ollama', 'base_url' );
            }

            if ( null === $defaultModel || '' === $defaultModel ) {
                $defaultModel = $this->providerConfigString( 'ollama', 'model' );
            }

            if ( null === $apiKey ) {
                $apiKey = '';
            }
        } elseif ( null === $apiKey || '' === $apiKey ) {
            return null;
        }

        if ( null !== $featureKey ) {
            $slug            = strtoupper( str_replace( [ '.', '-' ], '_', $featureKey ) );
            $featureModelEnv = getenv( 'ARTISANPACK_AI_' . $slug . '_MODEL' );

            // getenv() reads the actual process environment (populated at
            // process start), which survives `php artisan config:cache` in
            // production. env() reads dotenv-loaded state that is unreliable
            // in cached mode and is forbidden outside config files by
            // CLAUDE.md.
            if ( is_string( $featureModelEnv ) && '' !== $featureModelEnv ) {
                $defaultModel = $featureModelEnv;
            }
        }

        return new Credentials(
            provider: $provider,
            apiKey: $apiKey,
            defaultModel: $defaultModel,
            baseUrl: $baseUrl,
        );
    }

    /**
     * Read a string value from `artisanpack.ai.providers.<provider>.<field>`,
     * or null when unset / non-string.
     *
     * @since 1.0.0
     *
     * @param  string  $provider  Provider slug.
     * @param  string  $field     Field key inside the provider config.
     *
     * @return string|null
     */
    protected function providerConfigString( string $provider, string $field ): ?string
    {
        $value = $this->config->get( 'artisanpack.ai.providers.' . $provider . '.' . $field );

        if ( ! is_string( $value ) || '' === $value ) {
            return null;
        }

        return $value;
    }

    /**
     * Fetch a value from real process env first, falling back to a config
     * key.
     *
     * Historically this called `env()` directly, but that reads dotenv-loaded
     * state which is unreliable under `php artisan config:cache` — env vars
     * that fed the config-file defaults still work (they land in the merged
     * config), but the raw env branch of the precedence chain silently
     * disappears in production. `getenv()` reads the actual process
     * environment which is stable across cache modes.
     *
     * @since 1.0.0
     *
     * @param  string  $envKey     Env var name.
     * @param  string  $configKey  Fallback config key.
     *
     * @return string|null
     */
    protected function envOrConfig( string $envKey, string $configKey ): ?string
    {
        $value = getenv( $envKey );

        if ( is_string( $value ) && '' !== $value ) {
            return $value;
        }

        $fromConfig = $this->config->get( $configKey );

        if ( is_string( $fromConfig ) && '' !== $fromConfig ) {
            return $fromConfig;
        }

        return null;
    }
}
