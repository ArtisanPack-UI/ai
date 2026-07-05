<?php

declare( strict_types=1 );

use ArtisanPackUI\Ai\Jobs\CheckBudgetThresholdJob;
use ArtisanPackUI\Ai\Mail\BudgetWarningMail;
use ArtisanPackUI\Ai\Repositories\AiUsageRepository;
use ArtisanPackUI\Ai\Support\BudgetSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    $this->createSettingsTable();
    app( BudgetSettings::class )->resetSettingsTableProbe();
    config( [ 'artisanpack.ai.budget.recipients' => [ 'admin@example.test' ] ] );
} );

function seedCostForIdempotency( float $cost ): void
{
    DB::table( 'ai_usage_events' )->insert( [
        'feature_key'        => 'fake.echo',
        'package'            => 'artisanpack-ui/ai-fake',
        'provider'           => 'anthropic',
        'model'              => 'haiku',
        'input_tokens'       => 0,
        'output_tokens'      => 0,
        'estimated_cost_usd' => $cost,
        'cache_hit'          => false,
        'created_at'         => now()->toDateTimeString(),
    ] );
}

it( 'marks warning-sent BEFORE queueing mail so a crash between queue and mark cannot double-send', function (): void {
    Carbon::setTestNow( '2026-07-05 10:00:00' );

    /** @var BudgetSettings $settings */
    $settings = app( BudgetSettings::class );
    $settings->setMonthlyCap( 100.0 );
    seedCostForIdempotency( 85.0 );

    // Swap in a mailer factory whose `queue()` throws — simulating the
    // crash-after-queue-push case. If the job still marks the sent flag
    // before that queue call, the flag will already be set when the
    // exception bubbles. If mark comes after queue (the old order), the
    // flag would stay false and a retry would resend.
    $throwingFactory = new class implements Illuminate\Contracts\Mail\Factory {
        public function mailer( $name = null )
        {
            return new class {
                public function to( $address ): self
                {
                    return $this;
                }

                public function queue( $mailable ): void
                {
                    throw new RuntimeException( 'Simulated queue failure after push' );
                }
            };
        }
    };

    try {
        app( CheckBudgetThresholdJob::class )->handle(
            $settings,
            app( AiUsageRepository::class ),
            $throwingFactory,
            app( Illuminate\Contracts\Events\Dispatcher::class ),
        );
    } catch ( RuntimeException ) {
        // Expected.
    }

    // The flag must be set even though the mail dispatch blew up, so a
    // subsequent retry will short-circuit at warningSentFor() and not
    // produce a duplicate email.
    expect( $settings->warningSentFor( '2026-07' ) )->toBeTrue();

    // Prove the retry is a no-op with a real fake mailer.
    Mail::fake();
    app( CheckBudgetThresholdJob::class )->handle(
        $settings,
        app( AiUsageRepository::class ),
        app( Illuminate\Contracts\Mail\Factory::class ),
        app( Illuminate\Contracts\Events\Dispatcher::class ),
    );
    Mail::assertNotQueued( BudgetWarningMail::class );

    Carbon::setTestNow();
} );
