<?php

/**
 * Ai service provider.
 *
 * Bootstraps the Ai package: registers container singletons for future
 * foundation classes and merges/publishes the package configuration under
 * the shared `artisanpack.ai` config key.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ai;

use Illuminate\Support\ServiceProvider;

/**
 * Service provider for the Ai package.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */
class AiServiceProvider extends ServiceProvider
{
    /**
     * Registers any application services.
     *
     * Binds the main Ai class as a singleton so downstream code can resolve
     * it via the facade, helper, or container. Additional foundation
     * singletons will be registered here as the RFC is implemented.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/ai.php',
            'artisanpack-ai-temp',
        );

        $this->app->singleton( 'artisanpack.ai', function ( $app ) {
            return new Ai();
        } );
    }

    /**
     * Bootstraps any application services.
     *
     * Publishes the package configuration and merges defaults into the
     * shared `artisanpack.ai` config namespace so downstream packages read
     * from a single, consistent location.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function boot(): void
    {
        $this->mergeConfiguration();

        if ( $this->app->runningInConsole() ) {
            $this->publishes( [
                __DIR__ . '/../config/ai.php' => config_path( 'artisanpack/ai.php' ),
            ], 'artisanpack-package-config' );
        }
    }

    /**
     * Merges the package defaults with the user's customizations under
     * `artisanpack.ai`, giving the user's values precedence.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function mergeConfiguration(): void
    {
        $packageDefaults = config( 'artisanpack-ai-temp', [] );
        $userConfig      = config( 'artisanpack.ai', [] );
        $mergedConfig    = array_replace_recursive( $packageDefaults, $userConfig );
        config( [ 'artisanpack.ai' => $mergedConfig ] );
    }
}
