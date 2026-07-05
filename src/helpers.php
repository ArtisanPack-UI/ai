<?php

/**
 * Ai package helper functions.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */

use ArtisanPackUI\Ai\Ai;

if ( ! function_exists( 'ai' ) ) {
    /**
     * Get the shared Ai instance from the container.
     *
     * @since 1.0.0
     *
     * @return Ai
     */
    function ai(): Ai
    {
        return app( 'artisanpack.ai' );
    }
}
