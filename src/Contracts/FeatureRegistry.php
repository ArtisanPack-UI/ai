<?php

/**
 * Feature registry contract.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ai\Contracts;

use ArtisanPackUI\Ai\Registry\FeatureDefinition;
use Illuminate\Support\Collection;

/**
 * Central catalog of every AI feature in the ecosystem.
 *
 * Frozen for v1.x. Additions are permitted, but the existing method
 * signatures below are considered backwards-compatibility-critical and must
 * not change without a major version bump.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */
interface FeatureRegistry
{
    /**
     * Register an agent under a feature key.
     *
     * @since 1.0.0
     *
     * @param  string                              $featureKey  Dot-notation feature key (e.g. `seo.suggest_meta_description`).
     * @param  class-string                        $agentClass  Agent class implementing the feature.
     * @param  array<string, mixed>                $meta        Optional metadata (label, description, package, etc.).
     *
     * @return void
     */
    public function register( string $featureKey, string $agentClass, array $meta = [] ): void;

    /**
     * Retrieve every registered feature.
     *
     * Results are ordered deterministically by package then feature key.
     *
     * @since 1.0.0
     *
     * @return Collection<int, FeatureDefinition>
     */
    public function all(): Collection;

    /**
     * Retrieve a feature by its key.
     *
     * @since 1.0.0
     *
     * @param  string  $featureKey  Feature key to look up.
     *
     * @return FeatureDefinition|null The definition, or null when unregistered.
     */
    public function get( string $featureKey ): ?FeatureDefinition;

    /**
     * Determine whether a feature is currently enabled.
     *
     * Short-circuits to false when no credentials are configured for the
     * resolved provider.
     *
     * @since 1.0.0
     *
     * @param  string  $featureKey  Feature key to check.
     *
     * @return bool True when enabled and credentials are configured.
     */
    public function isEnabled( string $featureKey ): bool;

    /**
     * Determine whether the toggle state alone is on, ignoring credentials.
     *
     * Callers that need to separate the "administrator disabled this
     * feature" case from the "credentials missing" case should use this
     * method plus a direct `CredentialResolver` check rather than
     * `isEnabled()`.
     *
     * @since 1.0.0
     *
     * @param  string  $featureKey  Feature key to check.
     *
     * @return bool True when the feature is registered and toggle=on.
     */
    public function isToggleOn( string $featureKey ): bool;

    /**
     * Enable a feature and persist the toggle state.
     *
     * @since 1.0.0
     *
     * @param  string  $featureKey  Feature key to enable.
     *
     * @return void
     */
    public function enable( string $featureKey ): void;

    /**
     * Disable a feature and persist the toggle state.
     *
     * @since 1.0.0
     *
     * @param  string  $featureKey  Feature key to disable.
     *
     * @return void
     */
    public function disable( string $featureKey ): void;
}
