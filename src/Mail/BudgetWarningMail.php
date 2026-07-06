<?php

/**
 * Budget warning mailable.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ai\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Sent to configured admin recipients when month-to-date AI spend crosses
 * the warning threshold.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */
class BudgetWarningMail extends Mailable implements ShouldQueue
{
    use Queueable;

    /**
     * Build the mailable.
     *
     * @since 1.0.0
     *
     * @param  string  $month                 Month label, `YYYY-MM`.
     * @param  float   $spentUsd              Spend to date.
     * @param  float   $capUsd                Configured cap.
     * @param  float   $thresholdPercentage   Threshold percentage.
     */
    public function __construct(
        public string $month,
        public float $spentUsd,
        public float $capUsd,
        public float $thresholdPercentage,
    ) {
    }

    /**
     * Get the envelope.
     *
     * @since 1.0.0
     *
     * @return Envelope
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf(
                'AI spend has reached %d%% of your %s cap',
                (int) $this->thresholdPercentage,
                $this->month,
            ),
        );
    }

    /**
     * Get the content definition.
     *
     * @since 1.0.0
     *
     * @return Content
     */
    public function content(): Content
    {
        return new Content(
            view: 'artisanpack-ai::mail.budget-warning',
            with: [
                'month'               => $this->month,
                'spentUsd'            => $this->spentUsd,
                'capUsd'              => $this->capUsd,
                'thresholdPercentage' => $this->thresholdPercentage,
            ],
        );
    }
}
