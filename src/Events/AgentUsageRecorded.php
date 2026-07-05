<?php

/**
 * Agent usage recorded event.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ai\Events;

/**
 * Dispatched after an agent run completes with token-usage telemetry.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */
final class AgentUsageRecorded
{
    /**
     * Build the event.
     *
     * @since 1.0.0
     *
     * @param  string  $featureKey    Feature key that was executed.
     * @param  string  $package       Owning package name.
     * @param  string  $model         Model used for the request.
     * @param  int     $inputTokens   Input token count.
     * @param  int     $outputTokens  Output token count.
     * @param  bool    $cacheHit      Whether the response came from cache.
     */
    public function __construct(
        public readonly string $featureKey,
        public readonly string $package,
        public readonly string $model,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly bool $cacheHit = false,
    ) {
    }
}
