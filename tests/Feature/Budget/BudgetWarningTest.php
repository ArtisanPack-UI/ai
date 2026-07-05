<?php

declare( strict_types=1 );

use ArtisanPackUI\Ai\Events\BudgetThresholdCrossed;
use ArtisanPackUI\Ai\Jobs\CheckBudgetThresholdJob;
use ArtisanPackUI\Ai\Mail\BudgetWarningMail;
use ArtisanPackUI\Ai\Support\BudgetSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    $this->createSettingsTable();
    config( [ 'artisanpack.ai.budget.recipients' => [ 'admin@example.test' ] ] );
} );

/**
 * Seed the ai_usage_events table with a single row of `$cost` USD dated
 * mid-month.
 */
function seedCost( float $cost, ?string $createdAt = null ): void
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
        'created_at'         => $createdAt ?? now()->toDateTimeString(),
    ] );
}

it( 'sends no email when no cap is configured', function (): void {
    Mail::fake();
    Event::fake( [ BudgetThresholdCrossed::class ] );

    seedCost( 500.0 );

    app( CheckBudgetThresholdJob::class )->handle(
        app( BudgetSettings::class ),
        app( ArtisanPackUI\Ai\Repositories\AiUsageRepository::class ),
        app( Illuminate\Contracts\Mail\Factory::class ),
        app( Illuminate\Contracts\Events\Dispatcher::class ),
    );

    Mail::assertNothingQueued();
    Event::assertNotDispatched( BudgetThresholdCrossed::class );
} );

it( 'sends one email when month-to-date spend crosses 80% of the cap', function (): void {
    Carbon::setTestNow( '2026-07-05 10:00:00' );
    Mail::fake();
    Event::fake( [ BudgetThresholdCrossed::class ] );

    app( BudgetSettings::class )->setMonthlyCap( 100.0 );
    seedCost( 85.0 );

    app( CheckBudgetThresholdJob::class )->handle(
        app( BudgetSettings::class ),
        app( ArtisanPackUI\Ai\Repositories\AiUsageRepository::class ),
        app( Illuminate\Contracts\Mail\Factory::class ),
        app( Illuminate\Contracts\Events\Dispatcher::class ),
    );

    Mail::assertQueued( BudgetWarningMail::class, 1 );
    Event::assertDispatched( BudgetThresholdCrossed::class, function ( BudgetThresholdCrossed $event ): bool {
        return '2026-07' === $event->month
            && 85.0 === $event->spentUsd
            && 100.0 === $event->capUsd
            && 80.0 === $event->thresholdPercentage;
    } );

    expect( app( BudgetSettings::class )->warningSentFor( '2026-07' ) )->toBeTrue();
    expect( app( BudgetSettings::class )->currentBanner() )->not->toBeNull();

    Carbon::setTestNow();
} );

it( 'does not resend the warning in the same month once already sent', function (): void {
    Carbon::setTestNow( '2026-07-05 10:00:00' );
    Mail::fake();

    app( BudgetSettings::class )->setMonthlyCap( 100.0 );
    seedCost( 85.0 );

    $job = app( CheckBudgetThresholdJob::class );

    $job->handle(
        app( BudgetSettings::class ),
        app( ArtisanPackUI\Ai\Repositories\AiUsageRepository::class ),
        app( Illuminate\Contracts\Mail\Factory::class ),
        app( Illuminate\Contracts\Events\Dispatcher::class ),
    );

    $job->handle(
        app( BudgetSettings::class ),
        app( ArtisanPackUI\Ai\Repositories\AiUsageRepository::class ),
        app( Illuminate\Contracts\Mail\Factory::class ),
        app( Illuminate\Contracts\Events\Dispatcher::class ),
    );

    Mail::assertQueued( BudgetWarningMail::class, 1 );

    Carbon::setTestNow();
} );

it( 'resets the banner and can warn again in a new month', function (): void {
    Carbon::setTestNow( '2026-07-05 10:00:00' );
    Mail::fake();

    app( BudgetSettings::class )->setMonthlyCap( 100.0 );
    seedCost( 85.0 );

    app( CheckBudgetThresholdJob::class )->handle(
        app( BudgetSettings::class ),
        app( ArtisanPackUI\Ai\Repositories\AiUsageRepository::class ),
        app( Illuminate\Contracts\Mail\Factory::class ),
        app( Illuminate\Contracts\Events\Dispatcher::class ),
    );

    expect( app( BudgetSettings::class )->currentBanner()['month'] )->toBe( '2026-07' );

    // Advance to next month, seed fresh spend.
    Carbon::setTestNow( '2026-08-02 10:00:00' );
    seedCost( 90.0, '2026-08-01 12:00:00' );

    app( CheckBudgetThresholdJob::class )->handle(
        app( BudgetSettings::class ),
        app( ArtisanPackUI\Ai\Repositories\AiUsageRepository::class ),
        app( Illuminate\Contracts\Mail\Factory::class ),
        app( Illuminate\Contracts\Events\Dispatcher::class ),
    );

    Mail::assertQueued( BudgetWarningMail::class, 2 );
    expect( app( BudgetSettings::class )->currentBanner()['month'] )->toBe( '2026-08' );

    Carbon::setTestNow();
} );
