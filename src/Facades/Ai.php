<?php

/**
 * Ai Facade.
 *
 * Provides static access to the shared Ai foundation instance.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ai\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * Ai Facade.
 *
 * @see \ArtisanPackUI\Ai\Ai
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */
class Ai extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @since 1.0.0
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return 'artisanpack.ai';
    }
}
