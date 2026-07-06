<?php

declare( strict_types=1 );

use ArtisanPackUI\Ai\Contracts\FeatureRegistry;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Gate;
use Tests\Support\FakeAgent;

beforeEach( function (): void {
    $this->createSettingsTable();
    $this->clearFeatureRegistry();

    config()->set( 'artisanpack.ai.api.middleware', [ 'api', 'auth' ] );

    Gate::define( 'manage_ai_settings', fn ( $user ): bool => true === (bool) ( $user->is_admin ?? false ) );

    /** @var FeatureRegistry $registry */
    $registry = app( FeatureRegistry::class );
    $registry->register( 'fake.echo', FakeAgent::class, [
        'package' => 'artisanpack-ui/ai-fake',
        'label'   => 'Echo',
    ] );
    $registry->register( 'other.summarize', FakeAgent::class, [
        'package' => 'artisanpack-ui/other',
    ] );
} );

function apiAdmin(): Authenticatable
{
    $user           = new Authenticatable();
    $user->id       = 1;
    $user->is_admin = true;

    return $user;
}

function apiViewer(): Authenticatable
{
    $user           = new Authenticatable();
    $user->id       = 2;
    $user->is_admin = false;

    return $user;
}

it( 'returns 401 without authentication when listing features', function (): void {
    $this->getJson( '/api/artisanpack-ai/features' )
        ->assertUnauthorized();
} );

it( 'returns 403 when the caller lacks the ability', function (): void {
    $this->actingAs( apiViewer() )
        ->getJson( '/api/artisanpack-ai/features' )
        ->assertForbidden();
} );

it( 'lists every registered feature with its enabled state', function (): void {
    $response = $this->actingAs( apiAdmin() )
        ->getJson( '/api/artisanpack-ai/features' )
        ->assertOk()
        ->json( 'features' );

    expect( $response )->toHaveCount( 2 );

    $echo = collect( $response )->firstWhere( 'key', 'fake.echo' );
    expect( $echo['package'] )->toBe( 'artisanpack-ui/ai-fake' );
    expect( $echo['enabled'] )->toBeTrue();
} );

it( 'toggles a feature with an explicit body value', function (): void {
    /** @var FeatureRegistry $registry */
    $registry = app( FeatureRegistry::class );

    $this->actingAs( apiAdmin() )
        ->postJson( '/api/artisanpack-ai/features/fake.echo/toggle', [ 'enabled' => false ] )
        ->assertOk()
        ->assertJsonPath( 'feature.enabled', false );

    expect( $registry->isToggleOn( 'fake.echo' ) )->toBeFalse();
} );

it( 'flips the toggle when the request body omits enabled', function (): void {
    /** @var FeatureRegistry $registry */
    $registry = app( FeatureRegistry::class );
    $registry->disable( 'fake.echo' );

    $this->actingAs( apiAdmin() )
        ->postJson( '/api/artisanpack-ai/features/fake.echo/toggle' )
        ->assertOk()
        ->assertJsonPath( 'feature.enabled', true );

    expect( $registry->isToggleOn( 'fake.echo' ) )->toBeTrue();
} );

it( 'returns 404 for an unknown feature key', function (): void {
    $this->actingAs( apiAdmin() )
        ->postJson( '/api/artisanpack-ai/features/ghost.feature/toggle', [ 'enabled' => true ] )
        ->assertNotFound();
} );

it( 'blocks toggling without authentication', function (): void {
    $this->postJson( '/api/artisanpack-ai/features/fake.echo/toggle', [ 'enabled' => false ] )
        ->assertUnauthorized();
} );
