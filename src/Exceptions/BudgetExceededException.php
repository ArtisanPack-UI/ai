<?php

/**
 * Budget exceeded exception.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.2.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ai\Exceptions;

use RuntimeException;

/**
 * Thrown when a non-critical agent is executed after month-to-date AI spend
 * has reached the configured monthly hard cap.
 *
 * Only raised when `artisanpack.ai.budget.enforce_hard_cap` is enabled and a
 * positive cap is configured. Agents flagged `$critical = true` bypass the
 * cap and are never subject to this exception.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.2.0
 */
class BudgetExceededException extends RuntimeException
{
    /**
     * Build the exception for a given feature key and spend snapshot.
     *
     * @since 1.2.0
     *
     * @param  string  $featureKey  Feature key of the blocked agent.
     * @param  float   $spentUsd    Month-to-date spend in USD.
     * @param  float   $capUsd      Configured monthly cap in USD.
     *
     * @return self
     */
    public static function forFeature( string $featureKey, float $spentUsd, float $capUsd ): self
    {
        return new self( sprintf(
            'AI monthly budget cap reached for feature "%s": $%.2f spent of $%.2f cap.',
            $featureKey,
            $spentUsd,
            $capUsd,
        ) );
    }
}
