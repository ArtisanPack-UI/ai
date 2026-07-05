<?php

/**
 * Rotate AI credential encryption.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ai\Console\Commands;

use ArtisanPackUI\Ai\Credentials\SettingsCredentialStore;
use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;

/**
 * Re-encrypt stored AI credentials after an app-key rotation.
 *
 * Usage:
 *
 *     php artisan ai:credentials:rotate --old-key=base64:XYZ...
 *
 * The command decrypts the current ciphertext with the OLD key and
 * re-encrypts it with the current `APP_KEY`. Run this immediately after
 * rotating the app key while the old key is still available.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */
class RotateAiCredentialsCommand extends Command
{
    /**
     * {@inheritDoc}
     */
    protected $signature = 'ai:credentials:rotate {--old-key= : Previous APP_KEY (base64:... form) that credentials are currently encrypted with}';

    /**
     * {@inheritDoc}
     */
    protected $description = 'Re-encrypt stored AI credentials after an app-key rotation.';

    /**
     * Execute the command.
     *
     * @since 1.0.0
     *
     * @param  SettingsCredentialStore  $store  Credential store using the current app key.
     *
     * @return int
     */
    public function handle( SettingsCredentialStore $store ): int
    {
        $oldKeyInput = (string) $this->option( 'old-key' );

        if ( '' === $oldKeyInput ) {
            $this->error( 'The --old-key option is required.' );

            return self::FAILURE;
        }

        $previous = new Encrypter(
            $this->parseKey( $oldKeyInput ),
            (string) config( 'app.cipher', 'AES-256-CBC' ),
        );

        $count = $store->rotateEncryption( $previous );

        $this->info( sprintf( 'Re-encrypted %d AI credential row(s).', $count ) );

        return self::SUCCESS;
    }

    /**
     * Decode a base64 app key into its raw binary form.
     *
     * @since 1.0.0
     *
     * @param  string  $key  App key from user input.
     *
     * @return string
     */
    protected function parseKey( string $key ): string
    {
        if ( str_starts_with( $key, 'base64:' ) ) {
            return base64_decode( substr( $key, 7 ), true ) ?: '';
        }

        return $key;
    }
}
