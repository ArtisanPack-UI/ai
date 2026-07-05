<?php

/**
 * Missing credentials exception.
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
 * Thrown when an agent is executed but no provider credentials could be
 * resolved.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */
class MissingCredentialsException extends RuntimeException
{
    /**
     * Build the exception for a given feature key.
     *
     * @since 1.0.0
     *
     * @param  string  $featureKey  Feature key that was missing credentials.
     *
     * @return self
     */
    public static function forFeature( string $featureKey ): self
    {
        return new self( sprintf( 'No AI credentials configured for feature "%s".', $featureKey ) );
    }
}
