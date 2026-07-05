<?php

/**
 * Budget threshold crossed event.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ai\Events;

/**
 * Dispatched once per calendar month when month-to-date spend crosses the
 * user-configured warning threshold (80% of `ai.monthly_budget_usd` by
 * default).
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */
final class BudgetThresholdCrossed
{
    /**
     * Build the event.
     *
     * @since 1.0.0
     *
     * @param  string  $month       Month the threshold was crossed in, `YYYY-MM`.
     * @param  float   $spentUsd    Month-to-date spend in USD.
     * @param  float   $capUsd      Configured monthly cap in USD.
     * @param  float   $thresholdPercentage  Threshold percentage (e.g. 80.0).
     */
    public function __construct(
        public readonly string $month,
        public readonly float $spentUsd,
        public readonly float $capUsd,
        public readonly float $thresholdPercentage,
    ) {
    }
}
