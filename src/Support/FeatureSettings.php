<?php

/**
 * Read/write helper for per-feature agent overrides.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ai\Support;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Facades\Schema;

/**
 * Persistent per-feature overrides for agent model and instructions.
 *
 * The layer that survives request boundaries called out in the RFC: writes
 * hit the shared `settings` table (cms-framework Settings module) using the
 * key convention `ai_features.{feature_key}.{model|instructions}`. Reads
 * short-circuit to `null` when the table is absent so the ai package still
 * boots in env-only mode.
 *
 * Resolution precedence (highest first) — enforced by `ArtisanPackAgent`:
 *
 *   1. Runtime override (`withModel()`, etc.)
 *   2. `FeatureSettings` (this class — settings table)
 *   3. `artisanpack.ai.features.{key}` config
 *   4. Class-level default (`$defaultModel`, `instructions()`)
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */
final class FeatureSettings
{
    /**
     * Settings-key prefix for per-feature overrides.
     *
     * @since 1.0.0
     */
    public const KEY_PREFIX = 'ai_features.';

    /**
     * Memoised `settings` table probe.
     *
     * @since 1.0.0
     *
     * @var bool|null
     */
    protected ?bool $tableExists = null;

    /**
     * Build the helper.
     *
     * @since 1.0.0
     *
     * @param  ConfigRepository             $config  Config repository.
     * @param  ConnectionResolverInterface  $db      DB resolver.
     */
    public function __construct(
        protected ConfigRepository $config,
        protected ConnectionResolverInterface $db,
    ) {
    }

    /**
     * Retrieve the persisted model override for a feature.
     *
     * Returns null when nothing is stored — callers fall through to config
     * or the class-level default.
     *
     * @since 1.0.0
     *
     * @param  string  $featureKey  Feature key (dot notation).
     *
     * @return string|null
     */
    public function model( string $featureKey ): ?string
    {
        return $this->readSetting( $this->keyFor( $featureKey, 'model' ) );
    }

    /**
     * Retrieve the persisted instructions override for a feature.
     *
     * @since 1.0.0
     *
     * @param  string  $featureKey  Feature key (dot notation).
     *
     * @return string|null
     */
    public function instructions( string $featureKey ): ?string
    {
        return $this->readSetting( $this->keyFor( $featureKey, 'instructions' ) );
    }

    /**
     * Persist the model override for a feature. Null clears it.
     *
     * @since 1.0.0
     *
     * @param  string       $featureKey  Feature key.
     * @param  string|null  $model       Model identifier or null to clear.
     *
     * @return void
     */
    public function setModel( string $featureKey, ?string $model ): void
    {
        $this->writeSetting( $this->keyFor( $featureKey, 'model' ), $model, 'string' );
    }

    /**
     * Persist the instructions override for a feature. Null clears it.
     *
     * @since 1.0.0
     *
     * @param  string       $featureKey    Feature key.
     * @param  string|null  $instructions  Instructions string or null to clear.
     *
     * @return void
     */
    public function setInstructions( string $featureKey, ?string $instructions ): void
    {
        $this->writeSetting( $this->keyFor( $featureKey, 'instructions' ), $instructions, 'string' );
    }

    /**
     * Return the flattened list of all currently stored per-feature overrides.
     *
     * Shape: `[ feature_key => [ 'model' => string|null, 'instructions' => string|null ] ]`.
     * Used by the admin UI to render every feature's advanced overrides in
     * one pass without N+1 reads.
     *
     * @since 1.0.0
     *
     * @return array<string, array{ model: string|null, instructions: string|null }>
     */
    public function all(): array
    {
        if ( ! $this->settingsTableAvailable() ) {
            return [];
        }

        // Escape SQL LIKE metacharacters in the prefix so `ai_features.` (with
        // its literal `_`) doesn't accidentally match sibling namespaces like
        // `aiXfeatures.` or `ai.features.` — the `_` is a single-char
        // wildcard on MySQL/Postgres/SQLite. The ESCAPE clause is standard
        // SQL so it works across all three drivers; the column reference
        // is wrapped through the query grammar so identifier quoting stays
        // driver-appropriate.
        $connection = $this->db->connection();
        $escaped    = addcslashes( self::KEY_PREFIX, '\\_%' );
        $keyColumn  = $connection->getQueryGrammar()->wrap( 'key' );

        $rows = $connection->table( 'settings' )
            ->whereRaw( $keyColumn . ' LIKE ? ESCAPE ?', [ $escaped . '%', '\\' ] )
            ->pluck( 'value', 'key' )
            ->all();

        $features = [];

        foreach ( $rows as $key => $value ) {
            $suffix = substr( (string) $key, strlen( self::KEY_PREFIX ) );

            // We only care about the model / instructions overrides; toggle
            // state (ai_features.<key>.enabled) is owned by ArrayFeatureRegistry.
            if ( ! preg_match( '/^(.+)\.(model|instructions)$/', $suffix, $matches ) ) {
                continue;
            }

            $featureKey                        = $matches[1];
            $field                             = $matches[2];
            $features[ $featureKey ] ??= [ 'model' => null, 'instructions' => null ];
            $features[ $featureKey ][ $field ] = null === $value ? null : (string) $value;
        }

        return $features;
    }

    /**
     * Reset the memoised probe. Test-only hook.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function resetSettingsTableProbe(): void
    {
        $this->tableExists = null;
    }

    /**
     * Build the fully-qualified settings key for a feature+field pair.
     *
     * @since 1.0.0
     *
     * @param  string  $featureKey  Feature key.
     * @param  string  $field       `model` or `instructions`.
     *
     * @return string
     */
    protected function keyFor( string $featureKey, string $field ): string
    {
        return self::KEY_PREFIX . $featureKey . '.' . $field;
    }

    /**
     * Read a raw setting value, or null when the row is missing / table absent.
     *
     * @since 1.0.0
     *
     * @param  string  $key  Full settings key.
     *
     * @return string|null
     */
    protected function readSetting( string $key ): ?string
    {
        if ( ! $this->settingsTableAvailable() ) {
            return null;
        }

        $value = $this->db->connection()
            ->table( 'settings' )
            ->where( 'key', $key )
            ->value( 'value' );

        if ( null === $value ) {
            return null;
        }

        $string = (string) $value;

        return '' === $string ? null : $string;
    }

    /**
     * Upsert or delete a settings row.
     *
     * @since 1.0.0
     *
     * @param  string       $key    Full settings key.
     * @param  string|null  $value  Value to store, or null to delete the row.
     * @param  string       $type   Type marker written to the settings row.
     *
     * @return void
     */
    protected function writeSetting( string $key, ?string $value, string $type ): void
    {
        if ( ! $this->settingsTableAvailable() ) {
            return;
        }

        $connection = $this->db->connection();

        if ( null === $value || '' === $value ) {
            $connection->table( 'settings' )->where( 'key', $key )->delete();

            return;
        }

        $connection->table( 'settings' )->updateOrInsert(
            [ 'key' => $key ],
            [ 'value' => $value, 'type' => $type ],
        );
    }

    /**
     * Memoised `Schema::hasTable('settings')` probe.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    protected function settingsTableAvailable(): bool
    {
        if ( null === $this->tableExists ) {
            $this->tableExists = Schema::hasTable( 'settings' );
        }

        return $this->tableExists;
    }
}
