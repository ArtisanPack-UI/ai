<?php

/**
 * cms-framework Settings credential store.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ai\Credentials;

use Closure;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read/write AI credentials through cms-framework's `settings` table.
 *
 * The API key is encrypted at rest via Laravel `Crypt` and never round-trips
 * to the browser in plaintext. Reads must go through
 * `toPublicArray()` for admin UI serialisation.
 *
 * Keys used in the `settings` table:
 *
 *   - `ai_credentials.provider`
 *   - `ai_credentials.api_key` (encrypted)
 *   - `ai_credentials.default_model`
 *   - `ai_credentials.base_url`
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */
class SettingsCredentialStore
{
    /**
     * Settings key prefix used for credential fields.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const KEY_PREFIX = 'ai_credentials.';

    /**
     * Placeholder written when a credential row leaks through the generic
     * SettingsManager write path.
     *
     * Real writes go through `save()` which encrypts. Anything else should
     * be treated as "there was a key, we're not going to store it here."
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const REDACTED_MARKER = '__redacted__';

    /**
     * Factory returning the current Encrypter.
     *
     * Stored as a factory rather than an instance so a runtime APP_KEY
     * rotation on a long-running worker (Octane, Horizon) is picked up on
     * the next call.
     *
     * @since 1.0.0
     *
     * @var Closure(): Encrypter
     */
    protected Closure $encrypterFactory;

    /**
     * Memoised result of the `settings` table probe. Reset on writes so a
     * table created after boot is picked up automatically.
     *
     * @since 1.0.0
     *
     * @var bool|null
     */
    protected ?bool $tableExists = null;

    /**
     * Build the store.
     *
     * Accepts either a raw Encrypter (legacy — pinned to the app key at
     * construction time) or a `Closure(): Encrypter` (preferred — resolves
     * on every call so a runtime app-key rotation is picked up).
     *
     * @since 1.0.0
     *
     * @param  Closure(): Encrypter|Encrypter  $encrypter  Encrypter or factory returning one.
     */
    public function __construct( Encrypter|Closure $encrypter )
    {
        $this->encrypterFactory = $encrypter instanceof Closure
            ? $encrypter
            : static fn (): Encrypter => $encrypter;
    }

    /**
     * Determine whether the underlying `settings` table exists.
     *
     * Memoises the probe to avoid an information_schema lookup on every
     * credential resolution.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public function isAvailable(): bool
    {
        if ( null === $this->tableExists ) {
            $this->tableExists = Schema::hasTable( 'settings' );
        }

        return $this->tableExists;
    }

    /**
     * Load stored credentials.
     *
     * Returns null when the `settings` table is unavailable, no provider is
     * configured, or the stored API key cannot be decrypted. Ollama is a
     * special case: it accepts empty API keys because the daemon runs
     * locally. In that scenario the stored ciphertext row is optional and
     * an empty decrypted string is valid.
     *
     * @since 1.0.0
     *
     * @return Credentials|null
     */
    public function load(): ?Credentials
    {
        if ( ! $this->isAvailable() ) {
            return null;
        }

        $raw = $this->readRaw();

        if ( null === $raw['provider'] ) {
            return null;
        }

        $isOllama = 'ollama' === $raw['provider'];

        if ( null === $raw['api_key'] ) {
            if ( ! $isOllama ) {
                return null;
            }

            $apiKey = '';
        } else {
            try {
                $apiKey = $this->encrypter()->decryptString( $raw['api_key'] );
            } catch ( DecryptException $exception ) {
                return null;
            }
        }

        if ( '' === $apiKey && ! $isOllama ) {
            return null;
        }

        return new Credentials(
            provider: $raw['provider'],
            apiKey: $apiKey,
            defaultModel: $raw['default_model'],
            baseUrl: $raw['base_url'],
        );
    }

    /**
     * Persist a full credential set.
     *
     * The API key is encrypted before it hits the database. Every other
     * field is stored as plain text.
     *
     * @since 1.0.0
     *
     * @param  Credentials  $credentials  Credentials to persist.
     *
     * @return void
     */
    public function save( Credentials $credentials ): void
    {
        $this->write( 'provider', $credentials->provider );
        $this->write( 'api_key', $this->encrypter()->encryptString( $credentials->apiKey ) );
        $this->write( 'default_model', $credentials->defaultModel );
        $this->write( 'base_url', $credentials->baseUrl );
    }

    /**
     * Return a redacted array safe for admin UI serialisation.
     *
     * The plaintext API key is never included; consumers only see whether
     * one is present.
     *
     * @since 1.0.0
     *
     * @return array{ provider: string|null, api_key_present: bool, default_model: string|null, base_url: string|null }
     */
    public function toPublicArray(): array
    {
        $raw = $this->readRaw();

        return [
            'provider'        => $raw['provider'],
            'api_key_present' => null !== $raw['api_key'] && '' !== $raw['api_key'],
            'default_model'   => $raw['default_model'],
            'base_url'        => $raw['base_url'],
        ];
    }

    /**
     * Re-encrypt every stored credential using a new encrypter.
     *
     * The `$previous` encrypter (bound to the OLD app key) decrypts the
     * currently-stored ciphertext; the injected instance encrypter (bound
     * to the NEW key) re-encrypts the plaintext. Returns the number of
     * credential rows re-encrypted.
     *
     * @since 1.0.0
     *
     * @param  Encrypter  $previous  Encrypter using the previous app key.
     *
     * @return int Number of credential rows re-encrypted.
     */
    public function rotateEncryption( Encrypter $previous ): int
    {
        if ( ! $this->isAvailable() ) {
            return 0;
        }

        $ciphertext = $this->readCiphertext();

        if ( null === $ciphertext || '' === $ciphertext ) {
            return 0;
        }

        $plaintext = $previous->decryptString( $ciphertext );
        $this->write( 'api_key', $this->encrypter()->encryptString( $plaintext ) );

        return 1;
    }

    /**
     * Resolve the current Encrypter via the injected factory.
     *
     * @since 1.0.0
     *
     * @return Encrypter
     */
    protected function encrypter(): Encrypter
    {
        return ( $this->encrypterFactory )();
    }

    /**
     * Read every stored field in a single query.
     *
     * @since 1.0.0
     *
     * @return array{ provider: string|null, api_key: string|null, default_model: string|null, base_url: string|null }
     */
    protected function readRaw(): array
    {
        $empty = [
            'provider'      => null,
            'api_key'       => null,
            'default_model' => null,
            'base_url'      => null,
        ];

        if ( ! $this->isAvailable() ) {
            return $empty;
        }

        $keys = array_map(
            fn ( string $field ): string => self::KEY_PREFIX . $field,
            array_keys( $empty ),
        );

        $rows = DB::table( 'settings' )
            ->whereIn( 'key', $keys )
            ->pluck( 'value', 'key' )
            ->all();

        $result = $empty;

        foreach ( array_keys( $empty ) as $field ) {
            $value = $rows[ self::KEY_PREFIX . $field ] ?? null;

            if ( null !== $value ) {
                $result[ $field ] = (string) $value;
            }
        }

        return $result;
    }

    /**
     * Read the ciphertext currently stored under `api_key`.
     *
     * @since 1.0.0
     *
     * @return string|null
     */
    protected function readCiphertext(): ?string
    {
        return $this->readRaw()['api_key'];
    }

    /**
     * Upsert a single value into the `settings` table.
     *
     * A null value clears the row.
     *
     * @since 1.0.0
     *
     * @param  string       $field  Field name (without the shared prefix).
     * @param  string|null  $value  Value to store.
     *
     * @return void
     */
    protected function write( string $field, ?string $value ): void
    {
        $key = self::KEY_PREFIX . $field;

        if ( null === $value ) {
            DB::table( 'settings' )->where( 'key', $key )->delete();

            return;
        }

        DB::table( 'settings' )->updateOrInsert(
            [ 'key' => $key ],
            [ 'value' => $value, 'type' => 'string' ],
        );
    }
}
