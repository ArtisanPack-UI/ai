<?php

/**
 * JSON API routes for the AI admin surfaces.
 *
 * The route group is registered from `AiServiceProvider::registerApiRoutes()`
 * so the middleware, prefix, and enable flag can be sourced from config.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

use ArtisanPackUI\Ai\Http\Controllers\Api\FeaturesController;
use ArtisanPackUI\Ai\Http\Controllers\Api\SettingsController;
use ArtisanPackUI\Ai\Http\Controllers\Api\TestConnectionController;
use ArtisanPackUI\Ai\Http\Controllers\Api\UsageController;
use Illuminate\Support\Facades\Route;

Route::get( 'settings', [ SettingsController::class, 'show' ] )
    ->name( 'artisanpack-ai.api.settings.show' );

Route::put( 'settings', [ SettingsController::class, 'update' ] )
    ->name( 'artisanpack-ai.api.settings.update' );

Route::get( 'features', [ FeaturesController::class, 'index' ] )
    ->name( 'artisanpack-ai.api.features.index' );

Route::post( 'features/{featureKey}/toggle', [ FeaturesController::class, 'toggle' ] )
    ->where( 'featureKey', '[A-Za-z0-9._-]+' )
    ->name( 'artisanpack-ai.api.features.toggle' );

Route::get( 'usage', [ UsageController::class, 'index' ] )
    ->name( 'artisanpack-ai.api.usage.index' );

Route::post( 'test-connection', TestConnectionController::class )
    ->name( 'artisanpack-ai.api.test-connection' );
