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

        if ( null === $provider || null === $apiKey || '' === $provider || '' === $apiKey ) {
            return null;
        }

        $defaultModel = $this->envOrConfig( 'ARTISANPACK_AI_DEFAULT_MODEL', 'artisanpack.ai.default_model' );
        $baseUrl      = $this->envOrConfig( 'ARTISANPACK_AI_BASE_URL', 'artisanpack.ai.base_url' );

        if ( null !== $featureKey ) {
            $slug             = strtoupper( str_replace( [ '.', '-' ], '_', $featureKey ) );
            $featureModelEnv  = env( 'ARTISANPACK_AI_' . $slug . '_MODEL' );

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
     * Fetch a value from env first, falling back to a config key.
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
        $value = env( $envKey );

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
