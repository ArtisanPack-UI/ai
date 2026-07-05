<?php

declare( strict_types=1 );

use ArtisanPackUI\Ai\Credentials\Credentials;
use ArtisanPackUI\Ai\Credentials\SettingsCredentialStore;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;

beforeEach( function (): void {
    $this->createSettingsTable();
} );

it( 'encrypts the api key on save', function (): void {
    /** @var SettingsCredentialStore $store */
    $store = app( SettingsCredentialStore::class );

    $store->save( new Credentials( provider: 'anthropic', apiKey: 'sk-plaintext' ) );

    $stored = DB::table( 'settings' )
        ->where( 'key', 'ai_credentials.api_key' )
        ->value( 'value' );

    expect( $stored )->not->toBeNull();
    expect( $stored )->not->toContain( 'sk-plaintext' );

    $decrypted = app( 'encrypter' )->decryptString( $stored );

    expect( $decrypted )->toBe( 'sk-plaintext' );
} );

it( 'round-trips credentials through load()', function (): void {
    /** @var SettingsCredentialStore $store */
    $store = app( SettingsCredentialStore::class );

    $store->save( new Credentials(
        provider: 'openai',
        apiKey: 'sk-round-trip',
        defaultModel: 'gpt-4o',
        baseUrl: 'https://custom.example.com',
    ) );

    $loaded = $store->load();

    expect( $loaded )->toBeInstanceOf( Credentials::class );
    expect( $loaded->provider )->toBe( 'openai' );
    expect( $loaded->apiKey )->toBe( 'sk-round-trip' );
    expect( $loaded->defaultModel )->toBe( 'gpt-4o' );
    expect( $loaded->baseUrl )->toBe( 'https://custom.example.com' );
} );

it( 'returns a redacted array for admin UI', function (): void {
    /** @var SettingsCredentialStore $store */
    $store = app( SettingsCredentialStore::class );

    $store->save( new Credentials( provider: 'anthropic', apiKey: 'sk-secret' ) );

    $public = $store->toPublicArray();

    expect( $public )->toBe( [
        'provider'        => 'anthropic',
        'api_key_present' => true,
        'default_model'   => null,
        'base_url'        => null,
    ] );
    expect( json_encode( $public ) )->not->toContain( 'sk-secret' );
} );

it( 'never emits the plaintext key from Credentials debug info', function (): void {
    $creds = new Credentials( provider: 'anthropic', apiKey: 'sk-should-be-hidden' );

    $public = $creds->toPublicArray();
    expect( json_encode( $public ) )->not->toContain( 'sk-should-be-hidden' );

    $debug = $creds->__debugInfo();
    expect( json_encode( $debug ) )->not->toContain( 'sk-should-be-hidden' );

    expect( (string) $creds )->not->toContain( 'sk-should-be-hidden' );
} );

it( 'returns null on decrypt failure', function (): void {
    /** @var SettingsCredentialStore $store */
    $store = app( SettingsCredentialStore::class );

    DB::table( 'settings' )->insert( [
        [ 'key' => 'ai_credentials.provider', 'value' => 'anthropic', 'type' => 'string' ],
        [ 'key' => 'ai_credentials.api_key', 'value' => 'not-a-valid-ciphertext', 'type' => 'string' ],
    ] );

    expect( $store->load() )->toBeNull();
} );

it( 'rotates encryption when the app key changes', function (): void {
    $oldKey       = random_bytes( 32 );
    $oldEncrypter = new Encrypter( $oldKey, 'AES-256-CBC' );

    DB::table( 'settings' )->insert( [
        [ 'key' => 'ai_credentials.provider', 'value' => 'anthropic', 'type' => 'string' ],
        [ 'key' => 'ai_credentials.api_key', 'value' => $oldEncrypter->encryptString( 'sk-rotated' ), 'type' => 'string' ],
    ] );

    /** @var SettingsCredentialStore $store */
    $store = app( SettingsCredentialStore::class );

    $count = $store->rotateEncryption( $oldEncrypter );

    expect( $count )->toBe( 1 );

    $loaded = $store->load();

    expect( $loaded )->toBeInstanceOf( Credentials::class );
    expect( $loaded->apiKey )->toBe( 'sk-rotated' );
} );

it( 'resolves the encrypter lazily on every call', function (): void {
    $callCount = 0;

    $store = new SettingsCredentialStore(
        function () use ( &$callCount ) {
            $callCount++;

            return app( 'encrypter' );
        },
    );

    $store->save( new Credentials( provider: 'anthropic', apiKey: 'sk-lazy' ) );
    $store->load();

    // save() encrypts once; load() decrypts once — both go through the factory.
    expect( $callCount )->toBeGreaterThan( 1 );
} );

it( 'reads all four credential fields in a single query', function (): void {
    /** @var SettingsCredentialStore $store */
    $store = app( SettingsCredentialStore::class );

    $store->save( new Credentials(
        provider: 'anthropic',
        apiKey: 'sk-single-query',
        defaultModel: 'haiku',
        baseUrl: 'https://api.example.com',
    ) );

    DB::enableQueryLog();
    DB::flushQueryLog();

    $store->load();

    // Only count actual data SELECTs against `settings` — the memoised
    // Schema::hasTable() probe against sqlite_master doesn't count.
    $queries = collect( DB::getQueryLog() )
        ->filter( function ( array $entry ): bool {
            $sql = strtolower( $entry['query'] );

            return str_contains( $sql, 'from "settings"' ) || str_contains( $sql, 'from `settings`' );
        } );

    expect( $queries->count() )->toBe( 1 );
} );

it( 'returns unavailable when the settings table is missing', function (): void {
    Illuminate\Support\Facades\Schema::dropIfExists( 'settings' );

    /** @var SettingsCredentialStore $store */
    $store = app( SettingsCredentialStore::class );

    expect( $store->isAvailable() )->toBeFalse();
    expect( $store->load() )->toBeNull();
} );
