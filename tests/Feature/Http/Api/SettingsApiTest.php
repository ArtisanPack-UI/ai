<?php

declare( strict_types=1 );

use ArtisanPackUI\Ai\Contracts\FeatureRegistry;
use ArtisanPackUI\Ai\Credentials\Credentials;
use ArtisanPackUI\Ai\Credentials\SettingsCredentialStore;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Gate;
use Tests\Support\FakeAgent;

beforeEach( function (): void {
    $this->createSettingsTable();

    // Use the plain `auth` middleware so `actingAs()` works with the
    // default web guard. In production, the shipped default is
    // `['api', 'auth:sanctum']` — see config/ai.php.
    config()->set( 'artisanpack.ai.api.middleware', [ 'api', 'auth' ] );

    Gate::define( 'manage_ai_settings', fn ( $user ): bool => true === (bool) ( $user->is_admin ?? false ) );

    /** @var FeatureRegistry $registry */
    $registry = app( FeatureRegistry::class );
    $registry->register( 'fake.echo', FakeAgent::class, [ 'package' => 'artisanpack-ui/ai-fake' ] );
} );

function apiActingAsAdmin(): Authenticatable
{
    $user           = new Authenticatable();
    $user->id       = 1;
    $user->is_admin = true;

    return $user;
}

function apiActingAsUser(): Authenticatable
{
    $user           = new Authenticatable();
    $user->id       = 2;
    $user->is_admin = false;

    return $user;
}

it( 'returns 401 without authentication', function (): void {
    $this->getJson( '/api/artisanpack-ai/settings' )
        ->assertUnauthorized();
} );

it( 'returns 403 when the caller lacks the manage_ai_settings ability', function (): void {
    $this->actingAs( apiActingAsUser() )
        ->getJson( '/api/artisanpack-ai/settings' )
        ->assertForbidden();
} );

it( 'returns the public settings payload without the plaintext API key', function (): void {
    /** @var SettingsCredentialStore $store */
    $store = app( SettingsCredentialStore::class );
    $store->save( new Credentials(
        provider: 'anthropic',
        apiKey: 'sk-secret-do-not-leak',
        defaultModel: 'haiku',
    ) );

    $response = $this->actingAs( apiActingAsAdmin() )
        ->getJson( '/api/artisanpack-ai/settings' )
        ->assertOk()
        ->json();

    expect( $response['credentials']['provider'] )->toBe( 'anthropic' );
    expect( $response['credentials']['default_model'] )->toBe( 'haiku' );
    expect( $response['credentials']['api_key_present'] )->toBeTrue();
    expect( $response['credentials'] )->not->toHaveKey( 'api_key' );

    // Absolutely no route through the payload should reveal the plaintext.
    expect( json_encode( $response ) )->not->toContain( 'sk-secret-do-not-leak' );

    // Feature overrides list every registered feature.
    expect( $response['feature_overrides'] )->toHaveCount( 1 );
    expect( $response['feature_overrides'][0]['feature_key'] )->toBe( 'fake.echo' );
} );

it( 'updates credentials and per-feature overrides', function (): void {
    $response = $this->actingAs( apiActingAsAdmin() )
        ->putJson( '/api/artisanpack-ai/settings', [
            'provider'          => 'openai',
            'api_key'           => 'sk-new-key',
            'default_model'     => 'gpt-4o-mini',
            'feature_overrides' => [
                [
                    'feature_key'  => 'fake.echo',
                    'model'        => 'gpt-4o',
                    'instructions' => 'Always respond in haiku.',
                ],
            ],
        ] )
        ->assertOk()
        ->json();

    expect( $response['credentials']['provider'] )->toBe( 'openai' );
    expect( $response['credentials']['api_key_present'] )->toBeTrue();

    $stored = app( SettingsCredentialStore::class )->load();
    expect( $stored )->not->toBeNull();
    expect( $stored->apiKey )->toBe( 'sk-new-key' );
    expect( $stored->provider )->toBe( 'openai' );
} );

it( 'rejects an update without an API key for cloud providers', function (): void {
    $this->actingAs( apiActingAsAdmin() )
        ->putJson( '/api/artisanpack-ai/settings', [
            'provider' => 'anthropic',
        ] )
        ->assertStatus( 422 )
        ->assertJsonValidationErrors( [ 'api_key' ] );

    expect( app( SettingsCredentialStore::class )->load() )->toBeNull();
} );

it( 'accepts an Ollama update without an API key when a base URL is present', function (): void {
    $this->actingAs( apiActingAsAdmin() )
        ->putJson( '/api/artisanpack-ai/settings', [
            'provider'      => 'ollama',
            'base_url'      => 'http://127.0.0.1:11434',
            'default_model' => 'llama3.2:3b',
        ] )
        ->assertOk();

    $stored = app( SettingsCredentialStore::class )->load();
    expect( $stored->provider )->toBe( 'ollama' );
    expect( $stored->baseUrl )->toBe( 'http://127.0.0.1:11434' );
} );

it( 'rejects a silent provider switch that would rebind the stored key', function (): void {
    /** @var SettingsCredentialStore $store */
    $store = app( SettingsCredentialStore::class );
    $store->save( new Credentials(
        provider: 'openai',
        apiKey: 'sk-openai-original',
        defaultModel: 'gpt-4o',
    ) );

    $this->actingAs( apiActingAsAdmin() )
        ->putJson( '/api/artisanpack-ai/settings', [
            'provider'      => 'anthropic',
            'default_model' => 'claude-3-5-sonnet',
        ] )
        ->assertStatus( 422 )
        ->assertJsonValidationErrors( [ 'api_key' ] );

    $stored = app( SettingsCredentialStore::class )->load();
    expect( $stored->provider )->toBe( 'openai' );
    expect( $stored->apiKey )->toBe( 'sk-openai-original' );
} );

it( 'ignores overrides for features not currently registered', function (): void {
    $this->actingAs( apiActingAsAdmin() )
        ->putJson( '/api/artisanpack-ai/settings', [
            'provider'          => 'ollama',
            'base_url'          => 'http://127.0.0.1:11434',
            'feature_overrides' => [
                [ 'feature_key' => 'ghost.feature', 'model' => 'llama3.2:1b' ],
            ],
        ] )
        ->assertOk();

    $settings = app( ArtisanPackUI\Ai\Support\FeatureSettings::class );
    expect( $settings->all() )->not->toHaveKey( 'ghost.feature' );
} );
