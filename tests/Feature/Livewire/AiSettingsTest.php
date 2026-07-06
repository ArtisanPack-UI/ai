<?php

declare( strict_types=1 );

use ArtisanPackUI\Ai\Contracts\FeatureRegistry;
use ArtisanPackUI\Ai\Credentials\SettingsCredentialStore;
use ArtisanPackUI\Ai\Livewire\Admin\AiSettings;
use ArtisanPackUI\Ai\Support\FeatureSettings;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\FakeAgent;

beforeEach( function (): void {
    if ( ! class_exists( Livewire::class ) ) {
        $this->markTestSkipped( 'livewire/livewire is not installed.' );
    }

    $this->createSettingsTable();

    /** @var FeatureSettings $settings */
    $settings = app( FeatureSettings::class );
    $settings->resetSettingsTableProbe();

    /** @var FeatureRegistry $registry */
    $registry = app( FeatureRegistry::class );
    $registry->register( 'fake.echo', FakeAgent::class, [ 'package' => 'artisanpack-ui/ai-fake' ] );
} );

it( 'renders providers, features, and current credential state on mount', function (): void {
    /** @var SettingsCredentialStore $store */
    $store = app( SettingsCredentialStore::class );
    $store->save( new ArtisanPackUI\Ai\Credentials\Credentials(
        provider: 'anthropic',
        apiKey: 'sk-existing',
        defaultModel: 'haiku',
    ) );

    Livewire::test( AiSettings::class )
        ->assertSet( 'provider', 'anthropic' )
        ->assertSet( 'defaultModel', 'haiku' )
        ->assertSet( 'apiKeyPresent', true )
        // The plaintext API key is never round-tripped to the browser.
        ->assertSet( 'apiKey', null );
} );

it( 'saves credentials and clears the toast on success', function (): void {
    Livewire::test( AiSettings::class )
        ->set( 'provider', 'anthropic' )
        ->set( 'apiKey', 'sk-new-key' )
        ->set( 'defaultModel', 'haiku' )
        ->call( 'save' )
        ->assertHasNoErrors()
        ->assertSet( 'apiKeyPresent', true )
        ->assertSet( 'apiKey', null );

    $loaded = app( SettingsCredentialStore::class )->load();

    expect( $loaded )->not->toBeNull();
    expect( $loaded->apiKey )->toBe( 'sk-new-key' );
    expect( $loaded->provider )->toBe( 'anthropic' );
} );

it( 'rejects a save without an API key for cloud providers', function (): void {
    Livewire::test( AiSettings::class )
        ->set( 'provider', 'openai' )
        ->set( 'apiKey', null )
        ->set( 'defaultModel', 'gpt-4o' )
        ->call( 'save' )
        ->assertHasErrors( [ 'apiKey' ] );

    expect( app( SettingsCredentialStore::class )->load() )->toBeNull();
} );

it( 'accepts an Ollama save without an API key when a base URL is present', function (): void {
    Livewire::test( AiSettings::class )
        ->set( 'provider', 'ollama' )
        ->set( 'baseUrl', 'http://127.0.0.1:11434' )
        ->set( 'defaultModel', 'llama3.2:3b' )
        ->call( 'save' )
        ->assertHasNoErrors();

    $loaded = app( SettingsCredentialStore::class )->load();

    expect( $loaded )->not->toBeNull();
    expect( $loaded->provider )->toBe( 'ollama' );
    expect( $loaded->apiKey )->toBe( '' );
    expect( $loaded->baseUrl )->toBe( 'http://127.0.0.1:11434' );
} );

it( 'preserves the stored API key when the field is left blank on re-save', function (): void {
    /** @var SettingsCredentialStore $store */
    $store = app( SettingsCredentialStore::class );
    $store->save( new ArtisanPackUI\Ai\Credentials\Credentials(
        provider: 'anthropic',
        apiKey: 'sk-existing',
        defaultModel: 'haiku',
    ) );

    Livewire::test( AiSettings::class )
        ->set( 'defaultModel', 'sonnet' )
        // apiKey stays null — the user didn't touch it.
        ->call( 'save' )
        ->assertHasNoErrors();

    $loaded = $store->load();

    expect( $loaded->apiKey )->toBe( 'sk-existing' );
    expect( $loaded->defaultModel )->toBe( 'sonnet' );
} );

it( 'persists per-feature model + instructions overrides via FeatureSettings', function (): void {
    // Dot-notation keys can't be reached via Livewire's set() path syntax
    // (`featureOverrides.fake.echo.model` would nest three levels deep).
    // Set the whole array in one call instead.
    Livewire::test( AiSettings::class )
        ->set( 'provider', 'anthropic' )
        ->set( 'apiKey', 'sk-test' )
        ->set( 'featureOverrides', [
            'fake.echo' => [ 'model' => 'sonnet', 'instructions' => 'Say hi.' ],
        ] )
        ->call( 'save' )
        ->assertHasNoErrors();

    /** @var FeatureSettings $settings */
    $settings = app( FeatureSettings::class );
    $settings->resetSettingsTableProbe();

    expect( $settings->model( 'fake.echo' ) )->toBe( 'sonnet' );
    expect( $settings->instructions( 'fake.echo' ) )->toBe( 'Say hi.' );
} );

it( 'flashes a success toast when the connection test succeeds', function (): void {
    Http::fake( [
        'http://127.0.0.1:11434/api/tags' => Http::response( [ 'models' => [] ], 200 ),
    ] );

    Livewire::test( AiSettings::class )
        ->set( 'provider', 'ollama' )
        ->set( 'baseUrl', 'http://127.0.0.1:11434' )
        ->call( 'testConnection' )
        ->assertSet( 'toast.type', 'success' );
} );

it( 'flashes an error toast when the connection test fails', function (): void {
    Http::fake( [
        'http://127.0.0.1:11434/api/tags' => Http::response( '', 502 ),
    ] );

    Livewire::test( AiSettings::class )
        ->set( 'provider', 'ollama' )
        ->set( 'baseUrl', 'http://127.0.0.1:11434' )
        ->call( 'testConnection' )
        ->assertSet( 'toast.type', 'error' );
} );
