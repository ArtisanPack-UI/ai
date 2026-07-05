<?php

/**
 * Feature disabled exception.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ai\Exceptions;

use RuntimeException;

/**
 * Thrown when an agent is executed but its feature key is disabled in the
 * registry.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */
class FeatureDisabledException extends RuntimeException
{
    /**
     * Build the exception for a given feature key.
     *
     * @since 1.0.0
     *
     * @param  string  $featureKey  Feature key that was disabled.
     *
     * @return self
     */
    public static function forFeature( string $featureKey ): self
    {
        return new self( sprintf( 'AI feature "%s" is disabled.', $featureKey ) );
    }
}
