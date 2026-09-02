<?php

/**
 * Livewire concern that runs an AI agent call behind a shared error ladder.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.2.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ai\Livewire\Concerns;

use ArtisanPackUI\Ai\Exceptions\BudgetExceededException;
use ArtisanPackUI\Ai\Exceptions\FeatureDisabledException;
use ArtisanPackUI\Ai\Exceptions\FeatureError;
use ArtisanPackUI\Ai\Exceptions\MissingCredentialsException;
use Throwable;

/**
 * Wraps an AI agent invocation in the loading-state and exception-handling
 * ladder shared by every AI trigger component.
 *
 * Components hand the agent call to {@see runAiFeature()} as a closure. The
 * concern flips `$this->isLoading` on for the duration, resets
 * `$this->error`, and maps the five AI exception layers onto a user-facing
 * `$this->error` string:
 *
 * ```php
 * class InsightSummary extends Component
 * {
 *     use InteractsWithAiFeature;
 *
 *     public ?string $summary = null;
 *
 *     public function summarize(): void
 *     {
 *         $this->summary = null;
 *
 *         $this->runAiFeature( function (): void {
 *             $output        = InsightSummaryAgent::for( $this->input() )->run();
 *             $this->summary = (string) ( $output['summary'] ?? '' );
 *         } );
 *     }
 * }
 * ```
 *
 * The concern owns the shared `$isLoading` and `$error` properties so
 * consuming components drop their own copies. Component-specific output
 * fields are reset by the component before it calls `runAiFeature()`.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.2.0
 */
trait InteractsWithAiFeature
{
    /**
     * Whether an AI agent call is currently in flight.
     *
     * @since 1.2.0
     *
     * @var bool
     */
    public bool $isLoading = false;

    /**
     * User-facing error from the most recent agent call, or null on success.
     *
     * @since 1.2.0
     *
     * @var string|null
     */
    public ?string $error = null;

    /**
     * Run an AI agent call, translating its failure modes into `$this->error`.
     *
     * Clears `$this->error` and raises `$this->isLoading` before invoking the
     * callback, then lowers `$this->isLoading` again once it settles — whether
     * the callback returns or throws.
     *
     * @since 1.2.0
     *
     * @param  callable(): void  $callback  Performs the agent call and assigns its output onto the component.
     *
     * @return void
     */
    protected function runAiFeature( callable $callback ): void
    {
        $this->error     = null;
        $this->isLoading = true;

        try {
            $callback();
        } catch ( FeatureDisabledException $exception ) {
            $this->error = (string) __( 'This AI feature is disabled.' );
        } catch ( MissingCredentialsException $exception ) {
            $this->error = (string) __( 'AI credentials are not configured.' );
        } catch ( FeatureError $exception ) {
            $this->error = $exception->getMessage();
        } catch ( BudgetExceededException $exception ) {
            $this->error = (string) __( 'The AI monthly budget has been reached. Please try again later.' );
        } catch ( Throwable $exception ) {
            $this->error = (string) __( 'The AI agent could not complete this request.' );
        } finally {
            $this->isLoading = false;
        }
    }
}
