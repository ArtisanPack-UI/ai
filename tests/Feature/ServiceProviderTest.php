<?php

use ArtisanPackUI\Ai\Ai;
use ArtisanPackUI\Ai\Facades\Ai as AiFacade;

it( 'binds the Ai singleton in the container', function (): void {
    expect( app( 'artisanpack.ai' ) )->toBeInstanceOf( Ai::class );
} );

it( 'resolves the Ai facade to the shared instance', function (): void {
    expect( AiFacade::getFacadeRoot() )->toBeInstanceOf( Ai::class );
} );

it( 'exposes the shared instance through the ai() helper', function (): void {
    expect( ai() )->toBeInstanceOf( Ai::class );
} );

it( 'merges the package config into artisanpack.ai', function (): void {
    expect( config( 'artisanpack.ai.default' ) )->not->toBeNull();
    expect( config( 'artisanpack.ai.providers' ) )->toBeArray();
    expect( config( 'artisanpack.ai.features' ) )->toBeArray();
} );
