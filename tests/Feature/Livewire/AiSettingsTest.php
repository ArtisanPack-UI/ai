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
    // featureOverrides is now a positional list so wire:model doesn't split
    // dot-notation feature keys into nested paths. Mount populates one
    // entry per registered feature.
    $component = Livewire::test( AiSettings::class );

    $overrides = $component->get( 'featureOverrides' );

    // fake.echo is registered in beforeEach; mount() should have picked it up.
    expect( collect( $overrides )->pluck( 'feature_key' )->all() )->toContain( 'fake.echo' );

    $index = collect( $overrides )->search( fn ( $o ) => 'fake.echo' === $o['feature_key'] );
    expect( $index )->toBeInt();

    $overrides[ $index ]['model']        = 'sonnet';
    $overrides[ $index ]['instructions'] = 'Say hi.';

    $component
        ->set( 'provider', 'anthropic' )
        ->set( 'apiKey', 'sk-test' )
        ->set( 'featureOverrides', $overrides )
        ->call( 'save' )
        ->assertHasNoErrors();

    /** @var FeatureSettings $settings */
    $settings = app( FeatureSettings::class );
    $settings->resetSettingsTableProbe();

    expect( $settings->model( 'fake.echo' ) )->toBe( 'sonnet' );
    expect( $settings->instructions( 'fake.echo' ) )->toBe( 'Say hi.' );
} );

it( 'ignores overrides for features that are not currently registered', function (): void {
    Livewire::test( AiSettings::class )
        ->set( 'provider', 'anthropic' )
        ->set( 'apiKey', 'sk-test' )
        // Crafted payload that references a feature that isn't registered.
        ->set( 'featureOverrides', [
            [ 'feature_key' => 'nonexistent.feature', 'model' => 'evil', 'instructions' => 'x' ],
        ] )
        ->call( 'save' )
        ->assertHasNoErrors();

    /** @var FeatureSettings $settings */
    $settings = app( FeatureSettings::class );
    $settings->resetSettingsTableProbe();

    expect( $settings->model( 'nonexistent.feature' ) )->toBeNull();
    expect( $settings->instructions( 'nonexistent.feature' ) )->toBeNull();
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

it( 'clears the plaintext apiKey after testConnection so it never re-serialises', function (): void {
    Http::fake( [
        'https://api.anthropic.com/v1/models' => Http::response( [ 'data' => [] ], 200 ),
    ] );

    Livewire::test( AiSettings::class )
        ->set( 'provider', 'anthropic' )
        ->set( 'apiKey', 'sk-freshly-typed' )
        ->call( 'testConnection' )
        // The typed key must not survive the response — otherwise Livewire
        // dehydrates it back into the snapshot the browser can read.
        ->assertSet( 'apiKey', null );
} );

it( 'clears the plaintext apiKey when save() rejects with a validation error', function (): void {
    Livewire::test( AiSettings::class )
        ->set( 'provider', 'openai' )
        // baseUrl exceeds max:2048 to force a validation exception.
        ->set( 'baseUrl', str_repeat( 'a', 3000 ) )
        ->set( 'apiKey', 'sk-freshly-typed' )
        ->call( 'save' )
        ->assertHasErrors( [ 'baseUrl' ] )
        ->assertSet( 'apiKey', null );
} );

it( 'rejects a provider swap that would silently blank a stored cloud key', function (): void {
    /** @var SettingsCredentialStore $store */
    $store = app( SettingsCredentialStore::class );
    // Prior save: Ollama with an intentionally empty key. The api_key row
    // is now absent → api_key_present should be false.
    $store->save( new ArtisanPackUI\Ai\Credentials\Credentials(
        provider: 'ollama',
        apiKey: '',
        baseUrl: 'http://127.0.0.1:11434',
    ) );

    Livewire::test( AiSettings::class )
        // Mount picked up the current state (ollama + api_key_present=false).
        ->assertSet( 'provider', 'ollama' )
        ->assertSet( 'apiKeyPresent', false )
        // Admin switches provider to anthropic but leaves the API-key
        // field blank. Without the api_key_present ciphertext fix this
        // silently wrote an empty Anthropic key.
        ->set( 'provider', 'anthropic' )
        ->set( 'apiKey', null )
        ->call( 'save' )
        ->assertHasErrors( [ 'apiKey' ] );

    $loaded = $store->load();

    // Store should still hold the previous Ollama config — the failed save
    // does NOT silently overwrite it with an empty Anthropic row.
    expect( $loaded )->not->toBeNull();
    expect( $loaded->provider )->toBe( 'ollama' );
} );
