<?php

declare( strict_types=1 );

use ArtisanPackUI\Ai\Credentials\SettingsCredentialStore;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;

it( 'rotates encryption via the artisan command', function (): void {
    $this->createSettingsTable();

    $oldKey       = random_bytes( 32 );
    $oldEncrypter = new Encrypter( $oldKey, 'AES-256-CBC' );

    DB::table( 'settings' )->insert( [
        [ 'key' => 'ai_credentials.provider', 'value' => 'anthropic', 'type' => 'string' ],
        [ 'key' => 'ai_credentials.api_key', 'value' => $oldEncrypter->encryptString( 'sk-legacy' ), 'type' => 'string' ],
    ] );

    $this->artisan( 'ai:credentials:rotate', [ '--old-key' => 'base64:' . base64_encode( $oldKey ) ] )
        ->expectsOutputToContain( 'Re-encrypted 1 AI credential row' )
        ->assertExitCode( 0 );

    /** @var SettingsCredentialStore $store */
    $store = app( SettingsCredentialStore::class );

    $loaded = $store->load();

    expect( $loaded )->not->toBeNull();
    expect( $loaded->apiKey )->toBe( 'sk-legacy' );
} );

it( 'fails when --old-key is missing', function (): void {
    $this->artisan( 'ai:credentials:rotate' )
        ->assertExitCode( 1 );
} );
