<?php

declare( strict_types=1 );

use ArtisanPackUI\Ai\Credentials\Credentials;
use ArtisanPackUI\Ai\Credentials\SettingsCredentialStore;
use ArtisanPackUI\Ai\Support\ConnectionTester;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;

beforeEach( function (): void {
    $this->createSettingsTable();

    config()->set( 'artisanpack.ai.api.middleware', [ 'api', 'auth' ] );

    Gate::define( 'manage_ai_settings', fn ( $user ): bool => true === (bool) ( $user->is_admin ?? false ) );
} );

function testConnectionAdmin(): Authenticatable
{
    $user           = new Authenticatable();
    $user->id       = 1;
    $user->is_admin = true;

    return $user;
}

it( 'returns 401 without authentication', function (): void {
    $this->postJson( '/api/artisanpack-ai/test-connection', [ 'provider' => 'ollama' ] )
        ->assertUnauthorized();
} );

it( 'returns 200 with ok result when Ollama responds', function (): void {
    Http::fake( [
        'http://127.0.0.1:11434/api/tags' => Http::response( [ 'models' => [] ], 200 ),
    ] );

    $this->actingAs( testConnectionAdmin() )
        ->postJson( '/api/artisanpack-ai/test-connection', [
            'provider' => 'ollama',
            'base_url' => 'http://127.0.0.1:11434',
        ] )
        ->assertOk()
        ->assertJsonPath( 'result', ConnectionTester::RESULT_OK );
} );

it( 'returns 422 with an error result when the provider probe fails', function (): void {
    Http::fake( [
        'https://api.anthropic.com/v1/models' => Http::response( [ 'error' => 'nope' ], 401 ),
    ] );

    $this->actingAs( testConnectionAdmin() )
        ->postJson( '/api/artisanpack-ai/test-connection', [
            'provider' => 'anthropic',
            'api_key'  => 'sk-broken',
        ] )
        ->assertStatus( 422 )
        ->assertJsonPath( 'result', ConnectionTester::RESULT_ERROR );
} );

it( 'falls back to the stored API key when the body omits one', function (): void {
    /** @var SettingsCredentialStore $store */
    $store = app( SettingsCredentialStore::class );
    $store->save( new Credentials(
        provider: 'anthropic',
        apiKey: 'sk-stored-key',
        defaultModel: 'haiku',
    ) );

    Http::fake( [
        'https://api.anthropic.com/v1/models' => Http::response( [ 'data' => [] ], 200 ),
    ] );

    $this->actingAs( testConnectionAdmin() )
        ->postJson( '/api/artisanpack-ai/test-connection', [
            'provider' => 'anthropic',
        ] )
        ->assertOk()
        ->assertJsonPath( 'result', ConnectionTester::RESULT_OK );

    Http::assertSent( function ( $request ): bool {
        return 'sk-stored-key' === $request->header( 'x-api-key' )[0]
            || str_contains( (string) $request->header( 'authorization' )[0] ?? '', 'sk-stored-key' );
    } );
} );
