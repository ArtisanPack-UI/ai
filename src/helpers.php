<?php

/**
 * Ai package helper functions.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */

use ArtisanPackUI\Ai\Ai;
use ArtisanPackUI\Ai\Support\BudgetSettings;

if ( ! function_exists( 'apAiCurrentBudgetWarning' ) ) {
    /**
     * Return the current month's budget warning payload for the admin
     * banner, or null when no warning is active.
     *
     * @since 1.0.0
     *
     * @return array{ month: string, spent_usd: float, cap_usd: float, threshold_percentage: float }|null
     */
    function apAiCurrentBudgetWarning(): ?array
    {
        return app( BudgetSettings::class )->currentBanner();
    }
}

if ( ! function_exists( 'ai' ) ) {
    /**
     * Get the shared Ai instance from the container.
     *
     * @since 1.0.0
     *
     * @return Ai
     */
    function ai(): Ai
    {
        return app( 'artisanpack.ai' );
    }
}
