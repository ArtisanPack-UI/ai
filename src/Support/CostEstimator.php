<?php

/**
 * Cost estimator for AI usage events.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ai\Support;

use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Estimates the USD cost of a completion given provider, model, and token
 * counts.
 *
 * Reads a config-driven pricing table under `artisanpack.ai.pricing`. Users
 * can override entries by publishing the config and editing values; unknown
 * provider/model combinations return `0.0` so telemetry never breaks a run.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */
final class CostEstimator
{
    /**
     * Build the estimator.
     *
     * @since 1.0.0
     *
     * @param  ConfigRepository  $config  Config repository.
     */
    public function __construct( protected ConfigRepository $config )
    {
    }

    /**
     * Estimate cost in USD for a given provider/model and token counts.
     *
     * The pricing table is keyed as
     * `artisanpack.ai.pricing.<provider>.<model>` with `input_per_1k` and
     * `output_per_1k` values.
     *
     * @since 1.0.0
     *
     * @param  string  $provider      Provider name.
     * @param  string  $model         Model identifier.
     * @param  int     $inputTokens   Input token count.
     * @param  int     $outputTokens  Output token count.
     *
     * @return float
     */
    public function estimate( string $provider, string $model, int $inputTokens, int $outputTokens ): float
    {
        $rates = $this->rates( $provider, $model );

        if ( [] === $rates ) {
            return 0.0;
        }

        $inputRate  = (float) ( $rates['input_per_1k'] ?? 0.0 );
        $outputRate = (float) ( $rates['output_per_1k'] ?? 0.0 );

        return round(
            ( $inputTokens / 1000 ) * $inputRate
            + ( $outputTokens / 1000 ) * $outputRate,
            6,
        );
    }

    /**
     * Look up the pricing entry for a provider/model.
     *
     * Uses literal array-key lookups to preserve dot-notation model names
     * (e.g. `claude-3.5-sonnet`).
     *
     * @since 1.0.0
     *
     * @param  string  $provider  Provider name.
     * @param  string  $model     Model identifier.
     *
     * @return array<string, mixed>
     */
    protected function rates( string $provider, string $model ): array
    {
        $pricing = $this->config->get( 'artisanpack.ai.pricing', [] );

        if ( ! is_array( $pricing ) || '' === $provider ) {
            return [];
        }

        $providerEntry = $pricing[ $provider ] ?? null;

        if ( ! is_array( $providerEntry ) ) {
            return [];
        }

        $entry = $providerEntry[ $model ] ?? null;

        return is_array( $entry ) ? $entry : [];
    }
}
