<?php

/**
 * laravel/ai-backed AgentPrompter implementation.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ai\Support;

use ArtisanPackUI\Ai\Contracts\AgentPrompter;
use ArtisanPackUI\Ai\Credentials\Credentials;
use ArtisanPackUI\Ai\Exceptions\FeatureError;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\JsonSchema\JsonSchema;
use JsonException;
use Laravel\Ai\Files\Base64Image;
use Laravel\Ai\Files\File;
use Laravel\Ai\Files\LocalImage;
use Laravel\Ai\Files\RemoteImage;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\StructuredAnonymousAgent;
use Throwable;

/**
 * Default {@see AgentPrompter} implementation. Delegates to
 * {@see StructuredAnonymousAgent} so downstream apps get provider failover,
 * broadcast/queue dispatch, and `Ai::fake()` "for free".
 *
 * Passes the resolved {@see Credentials} through as the provider
 * configuration and forwards the resolved model straight to laravel/ai —
 * the concrete agents call `prompt()` without knowing anything about
 * laravel/ai's provider machinery.
 *
 * Downstream apps that want to bypass this (e.g. to use a different
 * provider entirely, or to short-circuit for offline testing) can rebind
 * {@see AgentPrompter} in the container.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */
class LaravelAiAgentPrompter implements AgentPrompter
{
    /**
     * {@inheritDoc}
     */
    public function prompt(
        Credentials $credentials,
        string $model,
        string $instructions,
        string|array $message,
        array $outputSchema,
    ): array {
        [ $prompt, $attachments ] = $this->normalizeMessage( $message );

        // Shared context payload for both hook fire sites. Deliberately
        // limited to what the prompter itself knows — feature keys live at
        // the agent layer, so listeners that need per-feature routing should
        // hang off the concrete agent's own hooks.
        $context = [
            'provider'     => $credentials->provider,
            'model'        => $model,
            'instructions' => $instructions,
            'attachments'  => count( $attachments ),
        ];

        /**
         * Filter the prompt string before it is sent to the model.
         *
         * Provides a uniform seam for safety prompts, PII scrubbing, and
         * context injection across every agent in the ecosystem.
         *
         * @hook  ap.ai.promptGenerated
         *
         * @since 1.1.0
         *
         * @param string               $prompt   Resolved prompt text.
         * @param array<string, mixed> $context  Provider, model, instructions, attachment count.
         */
        $prompt = (string) applyFilters( 'ap.ai.promptGenerated', $prompt, $context );

        // Build the schema map up-front so the SerializableClosure below
        // captures a plain array of Type instances instead of `$this` — a
        // queue/broadcast path that serializes the anonymous agent should
        // not pull the whole prompter (and everything in the container it
        // touches) through `serialize()`.
        $schemaProperties = $this->buildLaravelJsonSchema( $outputSchema );

        $agent = new StructuredAnonymousAgent(
            instructions: $instructions,
            messages: [],
            tools: [],
            schema: static fn () => $schemaProperties,
        );

        $providerName = $this->registerRuntimeProvider( $credentials );

        try {
            $response = $this->sendToProvider( $agent, $prompt, $attachments, $providerName, $model );
        } catch ( Throwable $exception ) {
            throw FeatureError::forFeature(
                '(prompter)',
                sprintf( 'provider call failed: %s', $exception->getMessage() ),
                $exception,
            );
        } finally {
            // Drop the runtime provider entry so long-running processes
            // (Octane, queue workers) don't accumulate one config key + API
            // key string per agent invocation. Config::set() with `null`
            // does *not* remove — we have to reach into the underlying
            // items array to actually free the memory.
            $this->releaseRuntimeProvider( $providerName );
        }

        /**
         * Fired after a model response is received.
         *
         * Standard audit/logging seam. Runs before JSON decoding so
         * listeners see the raw provider text — including cases where the
         * decode step below will reject the payload.
         *
         * @hook  ap.ai.responseReceived
         *
         * @since 1.1.0
         *
         * @param string               $response  Raw provider response text.
         * @param array<string, mixed> $context   Provider, model, instructions, attachment count.
         */
        doAction( 'ap.ai.responseReceived', $response->text, $context );

        return [
            'output'        => $this->decodeOutput( $response ),
            'input_tokens'  => $response->usage->promptTokens,
            'output_tokens' => $response->usage->completionTokens,
        ];
    }

    /**
     * Dispatch the assembled prompt to laravel/ai. Extracted so tests can
     * substitute a stubbed provider without spinning up `Ai::fake()`.
     *
     * @since 1.1.0
     *
     * @param  StructuredAnonymousAgent  $agent         Anonymous agent instance.
     * @param  string                    $prompt        Prompt text (post-filter).
     * @param  array<int, mixed>         $attachments   Typed attachments.
     * @param  string                    $providerName  Runtime provider config key.
     * @param  string                    $model         Resolved model identifier.
     *
     * @return AgentResponse
     */
    protected function sendToProvider(
        StructuredAnonymousAgent $agent,
        string $prompt,
        array $attachments,
        string $providerName,
        string $model,
    ): AgentResponse {
        return $agent->prompt(
            prompt: $prompt,
            attachments: $attachments,
            provider: $providerName,
            model: $model,
        );
    }

    /**
     * Free a runtime provider entry registered by {@see registerRuntimeProvider()}.
     *
     * @since 1.0.0
     *
     * @param  string  $name  Namespaced provider name.
     *
     * @return void
     */
    protected function releaseRuntimeProvider( string $name ): void
    {
        /** @var ConfigRepository $config */
        $config = app( ConfigRepository::class );

        $providers = $config->get( 'ai.providers', [] );

        if ( is_array( $providers ) && array_key_exists( $name, $providers ) ) {
            unset( $providers[ $name ] );
            $config->set( 'ai.providers', $providers );
        }
    }

    /**
     * Split a message payload into laravel/ai's `(prompt, attachments)` shape.
     *
     * String messages become the prompt with no attachments. Structured
     * arrays split parts of type `text` into the prompt and everything else
     * into attachments (image URLs, base64 data URIs, file paths).
     *
     * @since 1.0.0
     *
     * @param  array<int, array<string, mixed>>|string  $message  User message payload.
     *
     * @return array{ 0: string, 1: array<int, mixed> }
     */
    protected function normalizeMessage( string|array $message ): array
    {
        if ( is_string( $message ) ) {
            return [ $message, [] ];
        }

        $prompt      = '';
        $attachments = [];

        foreach ( $message as $part ) {
            if ( ! is_array( $part ) ) {
                continue;
            }

            $type = $part['type'] ?? null;

            if ( 'text' === $type && isset( $part['text'] ) && is_string( $part['text'] ) ) {
                $prompt .= ( '' === $prompt ? '' : "\n\n" ) . $part['text'];
                continue;
            }

            $file = $this->toFileAttachment( $part );

            if ( null !== $file ) {
                $attachments[] = $file;
            }
        }

        return [ $prompt, $attachments ];
    }

    /**
     * Convert an agent's typed attachment part into a laravel/ai
     * {@see File} instance the provider gateways know
     * how to serialize.
     *
     * Currently handles the `image` type in three sources (`url`, `path`,
     * `base64`) — matching what {@see AltTextGenerationAgent::normalizeImageReference()}
     * emits. Unknown shapes are dropped so a bogus part doesn't break the
     * whole request.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $part  Message part from an agent.
     *
     * @return File|null
     */
    protected function toFileAttachment( array $part ): ?File
    {
        $type   = $part['type'] ?? null;
        $source = $part['source'] ?? null;
        $value  = $part['value'] ?? null;

        if ( 'image' !== $type || ! is_string( $source ) || ! is_string( $value ) ) {
            return null;
        }

        $mime = isset( $part['mime'] ) && is_string( $part['mime'] ) ? $part['mime'] : null;

        return match ( $source ) {
            'url'    => new RemoteImage( $value, $mime ),
            'path'   => new LocalImage( $value, $mime ),
            'base64' => new Base64Image( $this->stripBase64Prefix( $value ), $mime ),
            default  => null,
        };
    }

    /**
     * Strip the `data:image/...;base64,` prefix off a data URI so laravel/ai
     * only sees the raw base64 payload. Bare base64 strings pass through
     * unchanged.
     *
     * @since 1.0.0
     *
     * @param  string  $value  Raw base64 string or data URI.
     *
     * @return string
     */
    protected function stripBase64Prefix( string $value ): string
    {
        if ( ! str_starts_with( $value, 'data:' ) ) {
            return $value;
        }

        $commaPos = strpos( $value, ',' );

        return false === $commaPos ? $value : substr( $value, $commaPos + 1 );
    }

    /**
     * Convert the agent's raw JSON-Schema array into the shape
     * {@see StructuredAnonymousAgent::schema()} expects — an
     * associative array of `[property_name => Illuminate\JsonSchema\Types\Type]`.
     *
     * Uses {@see JsonSchema::fromArray()} per property so callers keep
     * declaring the raw JSON-Schema shape from {@see ArtisanPackAgent::outputSchema()}
     * without depending on laravel/ai's fluent builder.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $outputSchema  Raw JSON-Schema-style array.
     *
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    protected function buildLaravelJsonSchema( array $outputSchema ): array
    {
        $properties = $outputSchema['properties'] ?? [];

        if ( ! is_array( $properties ) ) {
            return [];
        }

        $required = [];

        if ( isset( $outputSchema['required'] ) && is_array( $outputSchema['required'] ) ) {
            foreach ( $outputSchema['required'] as $name ) {
                if ( is_string( $name ) ) {
                    $required[ $name ] = true;
                }
            }
        }

        $built = [];

        foreach ( $properties as $name => $definition ) {
            if ( ! is_string( $name ) || ! is_array( $definition ) ) {
                continue;
            }

            $type = JsonSchema::fromArray( $definition );

            if ( isset( $required[ $name ] ) ) {
                $type = $type->required();
            }

            $built[ $name ] = $type;
        }

        return $built;
    }

    /**
     * Register a laravel/ai provider config under a namespaced key so we
     * can pass a string provider name to `Promptable::prompt()` — which is
     * what the failover machinery expects.
     *
     * Passing an inline array to `Promptable::prompt()` would be treated as
     * a `[provider => model]` map, so the key `driver` would be interpreted
     * as a provider name and blow up with "Instance driver [driver] is not
     * supported". The runtime-registered config route sidesteps that.
     *
     * @since 1.0.0
     *
     * @param  Credentials  $credentials  Resolved credentials.
     *
     * @return string Namespaced provider name to pass into laravel/ai.
     */
    protected function registerRuntimeProvider( Credentials $credentials ): string
    {
        // Per-call unique suffix so two concurrent requests (Octane, queue
        // worker) racing on the same driver can't clobber each other's key.
        // Underscores instead of dots because laravel/ai's Config lookup
        // uses dot-notation — a dotted name would nest under a fake
        // container and surface phantom providers in any code that
        // iterates `ai.providers` directly.
        $name = sprintf(
            'artisanpack_ai_runtime_%s_%s',
            preg_replace( '/[^A-Za-z0-9_]/', '_', $credentials->provider ),
            bin2hex( random_bytes( 6 ) ),
        );

        /** @var ConfigRepository $config */
        $config = app( ConfigRepository::class );

        $providerConfig = [
            'driver' => $credentials->provider,
        ];

        if ( '' !== $credentials->apiKey ) {
            $providerConfig['key'] = $credentials->apiKey;
        }

        if ( null !== $credentials->baseUrl && '' !== $credentials->baseUrl ) {
            $providerConfig['url'] = $credentials->baseUrl;
        }

        $config->set( 'ai.providers.' . $name, $providerConfig );

        return $name;
    }

    /**
     * Decode the model's JSON response into an associative array.
     *
     * @since 1.0.0
     *
     * @param  AgentResponse  $response  Provider response.
     *
     * @return array<string, mixed>
     */
    protected function decodeOutput( AgentResponse $response ): array
    {
        $payload = $this->stripCodeFence( $response->text );

        try {
            $decoded = json_decode( $payload, true, 512, JSON_THROW_ON_ERROR );
        } catch ( JsonException $exception ) {
            throw FeatureError::forFeature(
                '(prompter)',
                'model returned malformed JSON',
                $exception,
            );
        }

        if ( ! is_array( $decoded ) ) {
            throw FeatureError::forFeature( '(prompter)', 'model returned a non-object payload' );
        }

        return $decoded;
    }

    /**
     * Strip a leading/trailing markdown code fence off a model response.
     *
     * Anthropic's Haiku family occasionally emits structured output
     * wrapped in ` ```json ... ``` ` even under `StructuredAnonymousAgent`.
     * Rather than fail the whole call for a trivially recoverable payload,
     * strip the fence when it's present and hand the raw JSON to the
     * strict decoder. Plain JSON passes through unchanged.
     *
     * @since 1.0.0
     *
     * @param  string  $text  Raw response text from the provider.
     *
     * @return string
     */
    protected function stripCodeFence( string $text ): string
    {
        $trimmed = trim( $text );

        if ( ! str_starts_with( $trimmed, '```' ) ) {
            return $text;
        }

        // Drop the opening fence + optional language tag, then the trailing
        // fence. Anything between them is the JSON payload.
        $withoutOpen = (string) preg_replace( '/^```[A-Za-z0-9]*\s*\n?/', '', $trimmed );

        if ( str_ends_with( $withoutOpen, '```' ) ) {
            $withoutOpen = substr( $withoutOpen, 0, -3 );
        }

        return trim( $withoutOpen );
    }
}
