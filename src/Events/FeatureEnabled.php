<?php

/**
 * Feature enabled event.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ai\Events;

/**
 * Dispatched when a feature toggle transitions to enabled.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */
final class FeatureEnabled
{
    /**
     * Build the event.
     *
     * @since 1.0.0
     *
     * @param  string  $featureKey  Feature key that was enabled.
     */
    public function __construct( public readonly string $featureKey )
    {
    }
}
