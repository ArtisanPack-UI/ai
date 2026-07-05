<?php

/**
 * Feature registered event.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ai\Events;

use ArtisanPackUI\Ai\Registry\FeatureDefinition;

/**
 * Dispatched when a feature is registered with the FeatureRegistry.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */
final class FeatureRegistered
{
    /**
     * Build the event.
     *
     * @since 1.0.0
     *
     * @param  FeatureDefinition  $definition  The registered feature definition.
     */
    public function __construct( public readonly FeatureDefinition $definition )
    {
    }
}
