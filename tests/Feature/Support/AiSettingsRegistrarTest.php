<?php

declare( strict_types=1 );

use ArtisanPackUI\Ai\Contracts\FeatureRegistry;
use ArtisanPackUI\Ai\Credentials\SettingsCredentialStore;
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

it( 'registers credential, feature, budget, and cache settings when cms-framework is available', function (): void {
    if ( ! AiSettingsRegistrar::isCmsFrameworkAvailable() ) {
        $this->markTestSkipped( 'cms-framework is not autoloadable in this environment; integration guaranteed via the composed dev app.' );
    }

    /** @var FeatureRegistry $registry */
    $registry = app( FeatureRegistry::class );
    $registry->register( 'fake.echo', FakeAgent::class, [ 'package' => 'artisanpack-ui/ai-fake' ] );

    app( AiSettingsRegistrar::class )->register();

    $registered = applyFilters( 'ap.settings.registeredSettings', [] );

    expect( $registered )->toHaveKey( 'ai.credentials.provider' );
    expect( $registered )->toHaveKey( 'ai.credentials.api_key' );
    expect( $registered )->toHaveKey( 'ai.credentials.default_model' );
    expect( $registered )->toHaveKey( 'ai.credentials.base_url' );

    expect( $registered )->toHaveKey( 'ai.features.fake.echo.enabled' );
    expect( $registered )->toHaveKey( 'ai.features.fake.echo.model' );
    expect( $registered )->toHaveKey( 'ai.features.fake.echo.instructions' );

    expect( $registered )->toHaveKey( 'ai.budget.monthly_usd' );
    expect( $registered )->toHaveKey( 'ai.budget.warning_percentage' );

    expect( $registered )->toHaveKey( 'ai.cache.enabled' );
    expect( $registered )->toHaveKey( 'ai.cache.ttl' );
} );

it( 'api_key sanitizer redacts every non-empty value it receives', function (): void {
    if ( ! AiSettingsRegistrar::isCmsFrameworkAvailable() ) {
        $this->markTestSkipped( 'cms-framework is not autoloadable in this environment.' );
    }

    app( AiSettingsRegistrar::class )->register();

    $registered = applyFilters( 'ap.settings.registeredSettings', [] );

    $sanitizer = $registered['ai.credentials.api_key']['callback'];

    expect( $sanitizer( 'sk-real-key' ) )->toBe( SettingsCredentialStore::REDACTED_MARKER );
    expect( $sanitizer( '' ) )->toBe( '' );
} );
