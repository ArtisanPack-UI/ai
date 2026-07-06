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
use Illuminate\Queue\Middleware\WithoutOverlapping;
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
        $now   = Carbon::now();
        $month = $now->format( 'Y-m' );
        $cap   = $settings->monthlyCap();

        if ( null === $cap || $cap <= 0 ) {
            return;
        }

        // Clear any leftover banner from the prior month before we start
        // the spend check for this month. Use storedBanner() rather than
        // currentBanner() — the latter self-filters to the current month,
        // so it would return null for the very rows we need to clear.
        $existing = $settings->storedBanner();

        if ( null !== $existing && $existing['month'] !== $month ) {
            $settings->clearBanner();
        }

        if ( $settings->warningSentFor( $month ) ) {
            return;
        }

        $spent     = $repository->monthToDateCost( $now );
        $threshold = $settings->warningThresholdPercentage();
        $trigger   = $cap * ( $threshold / 100 );

        if ( $spent < $trigger ) {
            return;
        }

        // Mark BEFORE dispatching mail so a retry after a crash between
        // queue-push and mark can't send a second warning. Combined with
        // the WithoutOverlapping middleware below, this closes the race
        // both on retry and on concurrent dispatch. Failure mode is at
        // most one *dropped* email (never a duplicate), which is the
        // safer direction — the RFC promises "at most one email per
        // calendar month".
        $settings->markWarningSentFor( $month );
        $settings->setBanner( $month, $spent, $cap, $threshold );

        foreach ( $settings->warningRecipients() as $recipient ) {
            $mailer->mailer()->to( $recipient )->queue(
                new BudgetWarningMail( $month, $spent, $cap, $threshold ),
            );
        }

        $events->dispatch( new BudgetThresholdCrossed(
            month: $month,
            spentUsd: $spent,
            capUsd: $cap,
            thresholdPercentage: $threshold,
        ) );
    }

    /**
     * Queue middleware — prevent two workers from racing on the same
     * calendar month's warning check.
     *
     * @since 1.0.0
     *
     * @return array<int, mixed>
     */
    public function middleware(): array
    {
        return [
            ( new WithoutOverlapping( 'ai-budget-threshold:' . Carbon::now()->format( 'Y-m' ) ) )
                ->releaseAfter( 60 )
                ->expireAfter( 300 ),
        ];
    }
}
