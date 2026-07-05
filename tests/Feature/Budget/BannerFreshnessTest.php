<?php

declare( strict_types=1 );

use ArtisanPackUI\Ai\Support\BudgetSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    $this->createSettingsTable();
    // The BudgetSettings singleton is created before the settings table
    // exists in some test environments; reset the memoised probe so
    // subsequent reads see the freshly-created table.
    app( BudgetSettings::class )->resetSettingsTableProbe();
} );

it( 'currentBanner() returns null when the persisted month is not the current month', function (): void {
    Carbon::setTestNow( '2026-07-20 10:00:00' );
    /** @var BudgetSettings $settings */
    $settings = app( BudgetSettings::class );
    $settings->setBanner( '2026-07', 85.0, 100.0, 80.0 );

    // Same month → banner returned.
    expect( $settings->currentBanner() )->not->toBeNull();

    // Month rollover → banner self-filters even though the row is still there.
    Carbon::setTestNow( '2026-08-01 00:30:00' );

    expect( $settings->currentBanner() )->toBeNull();
    expect( $settings->storedBanner() )->not->toBeNull();
    expect( $settings->storedBanner()['month'] )->toBe( '2026-07' );

    Carbon::setTestNow();
} );
