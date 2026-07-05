<?php

/**
 * Job that purges stale ai_usage_events rows.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ai\Jobs;

use ArtisanPackUI\Ai\Repositories\AiUsageRepository;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * Removes usage events older than `artisanpack.ai.usage.retention_days`.
 *
 * Defaults to 90 days per the RFC. Setting the retention to `0` disables
 * the purge — the job runs but no rows are removed.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */
class PurgeUsageEventsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Execute the job.
     *
     * @since 1.0.0
     *
     * @param  ConfigRepository    $config      Config repository.
     * @param  AiUsageRepository   $repository  Usage repository.
     *
     * @return int Number of rows deleted.
     */
    public function handle( ConfigRepository $config, AiUsageRepository $repository ): int
    {
        $days = (int) $config->get( 'artisanpack.ai.usage.retention_days', 90 );

        if ( $days <= 0 ) {
            return 0;
        }

        return $repository->purgeOlderThan( Carbon::now()->subDays( $days ) );
    }
}
