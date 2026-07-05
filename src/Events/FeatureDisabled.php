<?php

/**
 * Feature disabled event.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ai\Events;

/**
 * Dispatched when a feature toggle transitions to disabled.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */
final class FeatureDisabled
{
    /**
     * Build the event.
     *
     * @since 1.0.0
     *
     * @param  string  $featureKey  Feature key that was disabled.
     */
    public function __construct( public readonly string $featureKey )
    {
    }
}
