<?php

/**
 * Read/write helper for the AI budget settings.
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
 * Reads and writes AI budget settings via the shared cms-framework
 * `settings` table when available, falling back to config otherwise.
 *
 * Kept in the ai package so downstream apps can consume it without a hard
 * dependency on cms-framework.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */
final class BudgetSettings
{
    /**
     * Setting key for the monthly cap.
     *
     * @since 1.0.0
     */
    public const MONTHLY_CAP_KEY = 'ai.monthly_budget_usd';

    /**
     * Setting-key prefix for the per-month "warning already sent" flag.
     *
     * @since 1.0.0
     */
    public const WARNING_SENT_PREFIX = 'ai.budget_warning_sent.';

    /**
     * Build the settings helper.
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
     * Retrieve the configured monthly cap in USD, or null when unset.
     *
     * @since 1.0.0
     *
     * @return float|null
     */
    public function monthlyCap(): ?float
    {
        $stored = $this->readSetting( self::MONTHLY_CAP_KEY );

        if ( null !== $stored ) {
            return (float) $stored;
        }

        $configValue = $this->config->get( 'artisanpack.ai.budget.monthly_usd' );

        if ( null === $configValue || '' === $configValue ) {
            return null;
        }

        return (float) $configValue;
    }

    /**
     * Persist a monthly cap (null clears it).
     *
     * @since 1.0.0
     *
     * @param  float|null  $capUsd  Cap value or null.
     *
     * @return void
     */
    public function setMonthlyCap( ?float $capUsd ): void
    {
        $this->writeSetting(
            self::MONTHLY_CAP_KEY,
            null === $capUsd ? null : (string) $capUsd,
            'float',
        );
    }

    /**
     * Whether the warning email has already been sent for the given month.
     *
     * @since 1.0.0
     *
     * @param  string  $month  Month label `YYYY-MM`.
     *
     * @return bool
     */
    public function warningSentFor( string $month ): bool
    {
        return null !== $this->readSetting( self::WARNING_SENT_PREFIX . $month );
    }

    /**
     * Mark the warning email as sent for the given month.
     *
     * @since 1.0.0
     *
     * @param  string  $month  Month label `YYYY-MM`.
     *
     * @return void
     */
    public function markWarningSentFor( string $month ): void
    {
        $this->writeSetting( self::WARNING_SENT_PREFIX . $month, '1', 'boolean' );
    }

    /**
     * Warning threshold as a percentage (defaults to 80.0).
     *
     * @since 1.0.0
     *
     * @return float
     */
    public function warningThresholdPercentage(): float
    {
        $percentage = (float) $this->config->get( 'artisanpack.ai.budget.warning_percentage', 80.0 );

        if ( $percentage <= 0 || $percentage > 100 ) {
            return 80.0;
        }

        return $percentage;
    }

    /**
     * Recipients for the warning email.
     *
     * @since 1.0.0
     *
     * @return list<string>
     */
    public function warningRecipients(): array
    {
        $recipients = $this->config->get( 'artisanpack.ai.budget.recipients', [] );

        if ( ! is_array( $recipients ) ) {
            return [];
        }

        return array_values( array_filter( array_map(
            fn ( $address ): string => is_string( $address ) ? trim( $address ) : '',
            $recipients,
        ) ) );
    }

    /**
     * Retrieve the persisted admin banner for the current month, if any.
     *
     * @since 1.0.0
     *
     * @return array{ month: string, spent_usd: float, cap_usd: float, threshold_percentage: float }|null
     */
    public function currentBanner(): ?array
    {
        $stored = $this->readSetting( 'ai.budget_banner' );

        if ( null === $stored ) {
            return null;
        }

        $decoded = json_decode( $stored, true );

        if ( ! is_array( $decoded ) || ! isset( $decoded['month'] ) ) {
            return null;
        }

        return [
            'month'                => (string) $decoded['month'],
            'spent_usd'            => (float) ( $decoded['spent_usd'] ?? 0 ),
            'cap_usd'              => (float) ( $decoded['cap_usd'] ?? 0 ),
            'threshold_percentage' => (float) ( $decoded['threshold_percentage'] ?? 0 ),
        ];
    }

    /**
     * Persist a banner payload for the current month.
     *
     * @since 1.0.0
     *
     * @param  string  $month                 Month label.
     * @param  float   $spentUsd              Spend to date.
     * @param  float   $capUsd                Cap value.
     * @param  float   $thresholdPercentage   Threshold percentage.
     *
     * @return void
     */
    public function setBanner( string $month, float $spentUsd, float $capUsd, float $thresholdPercentage ): void
    {
        $this->writeSetting(
            'ai.budget_banner',
            (string) json_encode( [
                'month'                => $month,
                'spent_usd'            => $spentUsd,
                'cap_usd'              => $capUsd,
                'threshold_percentage' => $thresholdPercentage,
            ] ),
            'json',
        );
    }

    /**
     * Clear the banner (called at the top of each new month).
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function clearBanner(): void
    {
        $this->writeSetting( 'ai.budget_banner', null, 'json' );
    }

    /**
     * Read a raw setting value or null.
     *
     * @since 1.0.0
     *
     * @param  string  $key  Setting key.
     *
     * @return string|null
     */
    protected function readSetting( string $key ): ?string
    {
        if ( ! Schema::hasTable( 'settings' ) ) {
            return null;
        }

        $value = $this->db->connection()->table( 'settings' )->where( 'key', $key )->value( 'value' );

        return null === $value ? null : (string) $value;
    }

    /**
     * Write (or delete when null) a raw setting value.
     *
     * @since 1.0.0
     *
     * @param  string       $key    Setting key.
     * @param  string|null  $value  Value or null to delete.
     * @param  string       $type   Type marker written to the settings row.
     *
     * @return void
     */
    protected function writeSetting( string $key, ?string $value, string $type ): void
    {
        if ( ! Schema::hasTable( 'settings' ) ) {
            return;
        }

        $connection = $this->db->connection();

        if ( null === $value ) {
            $connection->table( 'settings' )->where( 'key', $key )->delete();

            return;
        }

        $connection->table( 'settings' )->updateOrInsert(
            [ 'key' => $key ],
            [ 'value' => $value, 'type' => $type ],
        );
    }
}
