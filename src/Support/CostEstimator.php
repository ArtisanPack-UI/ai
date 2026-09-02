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
use Illuminate\Support\Facades\Log;

/**
 * Estimates the USD cost of a completion given provider, model, and token
 * counts.
 *
 * Reads a config-driven pricing table under `artisanpack.ai.pricing`. Users
 * can override entries by publishing the config and editing values. When a
 * model has no explicit entry but its provider does have a priced table, the
 * estimator logs a warning and falls back to that provider's highest known
 * rate so retired or mistyped model ids never silently estimate `0.0` and
 * quietly under-count spend. Providers with no priced entries (e.g. local
 * Ollama models) still resolve to `0.0` without a warning.
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
            $rates = $this->fallbackRates( $provider, $model );
        }

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
     * (e.g. `claude-sonnet-5`).
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

    /**
     * Resolve conservative fallback rates for a model with no explicit
     * pricing entry.
     *
     * When the provider has a priced table but the requested model is not in
     * it, this returns the provider's highest known per-1k input and output
     * rates and logs a warning. Over-estimating (rather than silently
     * returning `0.0`) keeps month-to-date spend visible and lets the budget
     * hard cap still engage for retired or mistyped model ids. Providers with
     * no priced entries — or whose entries are all `0.0`, such as locally hosted
     * Ollama models — return `[]` so genuinely free usage stays at `0.0`
     * without noise.
     *
     * @since 1.2.0
     *
     * @param  string  $provider  Provider name.
     * @param  string  $model     Model identifier.
     *
     * @return array<string, mixed>
     */
    protected function fallbackRates( string $provider, string $model ): array
    {
        $pricing = $this->config->get( 'artisanpack.ai.pricing', [] );

        if ( ! is_array( $pricing ) || '' === $provider ) {
            return [];
        }

        $providerEntry = $pricing[ $provider ] ?? null;

        if ( ! is_array( $providerEntry ) || [] === $providerEntry ) {
            return [];
        }

        $inputRate  = 0.0;
        $outputRate = 0.0;

        foreach ( $providerEntry as $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }

            $inputRate  = max( $inputRate, (float) ( $entry['input_per_1k'] ?? 0.0 ) );
            $outputRate = max( $outputRate, (float) ( $entry['output_per_1k'] ?? 0.0 ) );
        }

        if ( 0.0 === $inputRate && 0.0 === $outputRate ) {
            return [];
        }

        Log::warning(
            'ArtisanPack AI: no pricing entry for the requested model; falling back to the provider\'s highest known rate so budget tracking stays conservative.',
            [
                'provider'      => $provider,
                'model'         => $model,
                'input_per_1k'  => $inputRate,
                'output_per_1k' => $outputRate,
            ],
        );

        return [ 'input_per_1k' => $inputRate, 'output_per_1k' => $outputRate ];
    }
}
