<?php

/**
 * Hook name deprecation aliases for the Ai package.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.1.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ai\Support;

/**
 * Registers backwards-compatible hook name aliases so subscribers using
 * the old hook name continue to fire (with an info log) after a rename.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.1.0
 */
class HookAliases
{
    /**
     * Register all Ai hook deprecation aliases.
     *
     * Guarded so the package still boots when
     * `artisanpack-ui/hooks` <1.3 is installed.
     *
     * @since 1.1.0
     *
     * @return void
     */
    public static function register(): void
    {
        if ( ! function_exists( 'deprecateHook' ) ) {
            return;
        }

        deprecateHook( 'ap.ai.register-features', 'ap.ai.registerFeatures' );
    }
}
