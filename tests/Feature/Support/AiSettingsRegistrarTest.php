<?php

declare( strict_types=1 );

use ArtisanPackUI\Ai\Contracts\FeatureRegistry;
use ArtisanPackUI\Ai\Support\AiSettingsRegistrar;
use Tests\Support\FakeAgent;

it( 'register() is a no-op when cms-framework SettingsManager is not loadable', function (): void {
    // The class check runs at call time — if cms-framework is present in
    // this test environment (via the dev app's autoloader), the registrar
    // will register; if not, it's a silent no-op. Either outcome is
    // acceptable — the guarantee is "no exception."
    expect( fn () => app( AiSettingsRegistrar::class )->register() )
        ->not->toThrow( Throwable::class );
} );

it( 'picks up features registered after the registrar has already run', function (): void {
    if ( ! AiSettingsRegistrar::isCmsFrameworkAvailable() ) {
        $this->markTestSkipped( 'cms-framework is not autoloadable in this environment.' );
    }

    // Registrar runs BEFORE we register the feature.
    app( AiSettingsRegistrar::class )->register();

    // Feature registration lands after — this simulates a downstream
    // provider whose boot() executes after ai's.
    /** @var FeatureRegistry $registry */
    $registry = app( FeatureRegistry::class );
    $registry->register( 'late.arrival', FakeAgent::class, [ 'package' => 'downstream/pkg' ] );

    $registered = applyFilters( 'ap.settings.registeredSettings', [] );

    // Without lazy enumeration this key would be missing.
    expect( $registered )->toHaveKey( 'ai_features.late.arrival.enabled' );
    expect( $registered )->toHaveKey( 'ai_features.late.arrival.model' );
    expect( $registered )->toHaveKey( 'ai_features.late.arrival.instructions' );
} );

it( 'registers credential, feature, budget, and cache settings when cms-framework is available', function (): void {
    if ( ! AiSettingsRegistrar::isCmsFrameworkAvailable() ) {
        $this->markTestSkipped( 'cms-framework is not autoloadable in this environment; integration guaranteed via the composed dev app.' );
    }

    /** @var FeatureRegistry $registry */
    $registry = app( FeatureRegistry::class );
    $registry->register( 'fake.echo', FakeAgent::class, [ 'package' => 'artisanpack-ui/ai-fake' ] );

    app( AiSettingsRegistrar::class )->register();

    $registered = applyFilters( 'ap.settings.registeredSettings', [] );

    // Credentials group — aligned with SettingsCredentialStore::KEY_PREFIX
    // so a write through SettingsManager lands in the same row the store
    // reads. `api_key` is intentionally NOT registered: it's owned by the
    // encrypted store and a plaintext write would corrupt the ciphertext.
    expect( $registered )->toHaveKey( 'ai_credentials.provider' );
    expect( $registered )->not->toHaveKey( 'ai_credentials.api_key' );
    expect( $registered )->toHaveKey( 'ai_credentials.default_model' );
    expect( $registered )->toHaveKey( 'ai_credentials.base_url' );

    // Features group — aligned with FeatureSettings::KEY_PREFIX.
    expect( $registered )->toHaveKey( 'ai_features.fake.echo.enabled' );
    expect( $registered )->toHaveKey( 'ai_features.fake.echo.model' );
    expect( $registered )->toHaveKey( 'ai_features.fake.echo.instructions' );

    // Budget: aligned with BudgetSettings::MONTHLY_CAP_KEY.
    expect( $registered )->toHaveKey( 'ai.monthly_budget_usd' );

    // Cache (config-only surface for admin visibility).
    expect( $registered )->toHaveKey( 'ai_cache.enabled' );
    expect( $registered )->toHaveKey( 'ai_cache.ttl' );
} );
