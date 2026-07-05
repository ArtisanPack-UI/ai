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
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Schema;

/**
 * Writes one row per usage event with estimated cost.
 *
 * Implements `ShouldQueue` so persistence runs off the agent's hot path:
 * a burst of completions doesn't stall closing SSE frames, and a
 * temporarily degraded DB can't propagate `run()` failures. The
 * `ai_usage_events` schema probe is memoised on the singleton listener
 * so we don't hit `information_schema` per event.
 *
 * Silently no-ops when the table is missing or usage tracking is
 * disabled so the listener remains safe to register on hosts that
 * haven't run the migration yet.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */
final class PersistAgentUsage implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Memoised result of the `ai_usage_events` table probe.
     *
     * `null` = not yet probed. Once resolved, subsequent events skip the
     * schema call entirely — the listener is bound as a singleton so the
     * cached value lives for the process's lifetime.
     *
     * @since 1.0.0
     *
     * @var bool|null
     */
    protected ?bool $tableExists = null;

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

        if ( ! $this->tableAvailable() ) {
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

    /**
     * Reset the memoised probe. Test-only hook.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function resetTableProbe(): void
    {
        $this->tableExists = null;
    }

    /**
     * Memoised `Schema::hasTable('ai_usage_events')` probe.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    protected function tableAvailable(): bool
    {
        if ( null === $this->tableExists ) {
            $this->tableExists = Schema::hasTable( 'ai_usage_events' );
        }

        return $this->tableExists;
    }
}
