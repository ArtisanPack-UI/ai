<?php

/**
 * Nightly job that emits a warning when AI spend crosses the threshold.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ai\Jobs;

use ArtisanPackUI\Ai\Events\BudgetThresholdCrossed;
use ArtisanPackUI\Ai\Mail\BudgetWarningMail;
use ArtisanPackUI\Ai\Repositories\AiUsageRepository;
use ArtisanPackUI\Ai\Support\BudgetSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * Aggregates month-to-date cost and sends one warning email per calendar
 * month when the configured threshold is crossed.
 *
 * The job is safe to run daily — the idempotency check on the settings
 * store guarantees at most one email per month. Runs at the start of a
 * new month reset any leftover banner.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */
class CheckBudgetThresholdJob implements ShouldQueue
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
     * @param  BudgetSettings      $settings    Settings helper.
     * @param  AiUsageRepository   $repository  Usage repository.
     * @param  MailFactory         $mailer      Mail factory.
     * @param  Dispatcher          $events      Event dispatcher.
     *
     * @return void
     */
    public function handle(
        BudgetSettings $settings,
        AiUsageRepository $repository,
        MailFactory $mailer,
        Dispatcher $events,
    ): void {
        $now      = Carbon::now();
        $month    = $now->format( 'Y-m' );
        $cap      = $settings->monthlyCap();
        $existing = $settings->currentBanner();

        // New month: clear any leftover banner from last cycle.
        if ( null !== $existing && $existing['month'] !== $month ) {
            $settings->clearBanner();
        }

        if ( null === $cap || $cap <= 0 ) {
            return;
        }

        if ( $settings->warningSentFor( $month ) ) {
            return;
        }

        $spent      = $repository->monthToDateCost( $now );
        $threshold  = $settings->warningThresholdPercentage();
        $trigger    = $cap * ( $threshold / 100 );

        if ( $spent < $trigger ) {
            return;
        }

        foreach ( $settings->warningRecipients() as $recipient ) {
            $mailer->mailer()->to( $recipient )->queue(
                new BudgetWarningMail( $month, $spent, $cap, $threshold ),
            );
        }

        $settings->markWarningSentFor( $month );
        $settings->setBanner( $month, $spent, $cap, $threshold );

        $events->dispatch( new BudgetThresholdCrossed(
            month: $month,
            spentUsd: $spent,
            capUsd: $cap,
            thresholdPercentage: $threshold,
        ) );
    }
}
