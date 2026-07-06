<?php

/**
 * Credential resolver contract.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ai\Contracts;

use ArtisanPackUI\Ai\Credentials\Credentials;

/**
 * Resolves BYOK provider credentials from env vars or database settings.
 *
 * Resolution order is frozen for v1.x:
 *
 *   1. Explicit runtime override (`$agent->withCredentials(...)`)
 *   2. cms-framework Settings (`ai_credentials.*`, encrypted at rest)
 *   3. Env vars (`ARTISANPACK_AI_*`)
 *   4. `null` — feature disabled
 *
 * Partial credentials (provider without key, or key without provider) are
 * never returned; the resolver returns `null` instead.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */
interface CredentialResolver
{
    /**
     * Resolve credentials, optionally for a specific feature.
     *
     * @since 1.0.0
     *
     * @param  string|null  $featureKey  Optional feature key for per-feature overrides.
     *
     * @return Credentials|null Resolved credentials, or null when none are configured.
     */
    public function resolve( ?string $featureKey = null ): ?Credentials;

    /**
     * Determine whether any credentials are configured for any provider.
     *
     * @since 1.0.0
     *
     * @return bool True when at least one provider has credentials.
     */
    public function hasAny(): bool;

    /**
     * Resolve credentials for a specific feature.
     *
     * Shortcut for `resolve( $featureKey )`.
     *
     * @since 1.0.0
     *
     * @param  string  $featureKey  Feature key to resolve for.
     *
     * @return Credentials|null Resolved credentials, or null when none are configured.
     */
    public function forFeature( string $featureKey ): ?Credentials;
}
