<?php

/**
 * Listener that persists AgentUsageRecorded events to `ai_usage_events`.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ai\Listeners;

use ArtisanPackUI\Ai\Events\AgentUsageRecorded;
use ArtisanPackUI\Ai\Support\CostEstimator;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Facades\Schema;

/**
 * Writes one row per usage event with estimated cost.
 *
 * Silently no-ops when the `ai_usage_events` table is missing or when
 * `artisanpack.ai.usage.enabled` is false, so the listener is safe to
 * register on hosts that haven't run the migration yet.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */
final class PersistAgentUsage
{
    /**
     * Build the listener.
     *
     * @since 1.0.0
     *
     * @param  ConfigRepository             $config    Config repository.
     * @param  CostEstimator                $estimator Cost estimator.
     * @param  ConnectionResolverInterface  $db        Database connection resolver.
     */
    public function __construct(
        protected ConfigRepository $config,
        protected CostEstimator $estimator,
        protected ConnectionResolverInterface $db,
    ) {
    }

    /**
     * Handle the event.
     *
     * @since 1.0.0
     *
     * @param  AgentUsageRecorded  $event  Fired event.
     *
     * @return void
     */
    public function handle( AgentUsageRecorded $event ): void
    {
        if ( ! (bool) $this->config->get( 'artisanpack.ai.usage.enabled', true ) ) {
            return;
        }

        if ( ! Schema::hasTable( 'ai_usage_events' ) ) {
            return;
        }

        $cost = $event->cacheHit
            ? 0.0
            : $this->estimator->estimate(
                $event->provider,
                $event->model,
                $event->inputTokens,
                $event->outputTokens,
            );

        $this->db->connection()->table( 'ai_usage_events' )->insert( [
            'feature_key'        => $event->featureKey,
            'package'            => $event->package,
            'provider'           => $event->provider,
            'model'              => $event->model,
            'input_tokens'       => $event->inputTokens,
            'output_tokens'      => $event->outputTokens,
            'estimated_cost_usd' => $cost,
            'cache_hit'          => $event->cacheHit,
            'created_at'         => now(),
        ] );
    }
}
