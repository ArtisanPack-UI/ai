<?php

/**
 * Livewire concern that exposes a feature toggle as a computed property.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.2.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ai\Livewire\Concerns;

use ArtisanPackUI\Ai\Contracts\FeatureRegistry;

/**
 * Adds a computed `$this->isEnabled` property that reflects the current
 * toggle state of `$this->featureKey`.
 *
 * AI trigger components (meta-description suggestors, insight summaries, and
 * the like) all need to no-op when an administrator has switched their
 * feature off. Rather than copy the same guard into every component, a
 * component declares a `$featureKey` and gets the check for free:
 *
 * ```php
 * class InsightSummary extends Component
 * {
 *     use ChecksFeatureToggle;
 *
 *     protected string $featureKey = 'analytics.insight_summary';
 * }
 * ```
 *
 * The check fails **closed**: `FeatureRegistry::isToggleOn()` already returns
 * false for an unregistered key, so a component whose feature was never
 * registered — or whose registry has not been populated — reports disabled
 * rather than silently exposing the feature.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.2.0
 *
 * @property-read bool $isEnabled Whether `$this->featureKey` is toggled on.
 */
trait ChecksFeatureToggle
{
    /**
     * Whether the component's feature is currently toggled on.
     *
     * Resolved as the Livewire computed property `$this->isEnabled`.
     *
     * @since 1.2.0
     *
     * @return bool True when `$this->featureKey` is registered and toggle=on.
     */
    public function getIsEnabledProperty(): bool
    {
        return app( FeatureRegistry::class )->isToggleOn( $this->featureKey );
    }
}
