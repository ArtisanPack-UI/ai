<?php

declare( strict_types=1 );

use ArtisanPackUI\Ai\Contracts\CredentialResolver;
use ArtisanPackUI\Ai\Contracts\FeatureRegistry;
use ArtisanPackUI\Ai\Credentials\ChainedCredentialResolver;
use ArtisanPackUI\Ai\Credentials\Credentials;
use ArtisanPackUI\Ai\Jobs\PurgeUsageEventsJob;
use ArtisanPackUI\Ai\Repositories\AiUsageRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeAgent;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    /** @var ChainedCredentialResolver $resolver */
    $resolver = app( CredentialResolver::class );
    $resolver->setOverride( new Credentials( provider: 'anthropic', apiKey: 'sk-test', defaultModel: 'haiku' ) );
    $resolver->useStore( fn () => null );

    /** @var FeatureRegistry $registry */
    $registry = app( FeatureRegistry::class );
    $registry->register( 'fake.echo', FakeAgent::class, [ 'package' => 'artisanpack-ui/ai-fake' ] );
} );

it( 'writes one ai_usage_events row per fake agent run', function (): void {
    FakeAgent::for( 'hello' )->run();

    $rows = DB::table( 'ai_usage_events' )->get();

    expect( $rows )->toHaveCount( 1 );
    expect( $rows[0]->feature_key )->toBe( 'fake.echo' );
    expect( $rows[0]->package )->toBe( 'artisanpack-ui/ai-fake' );
    expect( $rows[0]->provider )->toBe( 'anthropic' );
    expect( $rows[0]->model )->toBe( 'haiku' );
    expect( (int) $rows[0]->input_tokens )->toBe( 42 );
    expect( (int) $rows[0]->output_tokens )->toBe( 7 );
    expect( (float) $rows[0]->estimated_cost_usd )->toBeGreaterThan( 0.0 );
    expect( (bool) $rows[0]->cache_hit )->toBeFalse();
} );

it( 'aggregates 100 runs across 3 features per feature and per day', function (): void {
    Carbon::setTestNow( '2026-07-05 10:00:00' );

    /** @var FeatureRegistry $registry */
    $registry = app( FeatureRegistry::class );

    // Register three distinct agents with distinct feature keys.
    $features = [
        [ 'key' => 'seo.summary',        'class' => makeAgentClass( 'seo.summary', 'artisanpack-ui/seo' ) ],
        [ 'key' => 'content.digest',     'class' => makeAgentClass( 'content.digest', 'artisanpack-ui/content' ) ],
        [ 'key' => 'privacy.classifier', 'class' => makeAgentClass( 'privacy.classifier', 'artisanpack-ui/privacy' ) ],
    ];

    foreach ( $features as $entry ) {
        $registry->register( $entry['key'], $entry['class'], [ 'package' => explode( '/', $entry['class'] )[0] ] );
    }

    for ( $i = 0; $i < 100; $i++ ) {
        $feature = $features[ $i % 3 ];
        $feature['class']::for( 'input-' . $i )->run();
    }

    /** @var AiUsageRepository $repo */
    $repo = app( AiUsageRepository::class );

    $byFeature = collect( $repo->byFeature() )->keyBy( 'feature_key' );

    expect( $byFeature )->toHaveKey( 'seo.summary' );
    expect( $byFeature )->toHaveKey( 'content.digest' );
    expect( $byFeature )->toHaveKey( 'privacy.classifier' );

    expect( $byFeature['seo.summary']['events'] )->toBe( 34 );
    expect( $byFeature['content.digest']['events'] )->toBe( 33 );
    expect( $byFeature['privacy.classifier']['events'] )->toBe( 33 );

    $byDay = $repo->byPeriod( 'day' );
    expect( $byDay )->toHaveCount( 1 );
    expect( $byDay[0]['events'] )->toBe( 100 );

    Carbon::setTestNow();
} );

it( 'purge job deletes rows older than the retention_days config', function (): void {
    Carbon::setTestNow( '2026-07-05 10:00:00' );

    DB::table( 'ai_usage_events' )->insert( [
        [
            'feature_key'        => 'fake.echo',
            'package'            => 'artisanpack-ui/ai-fake',
            'provider'           => 'anthropic',
            'model'              => 'haiku',
            'input_tokens'       => 0,
            'output_tokens'      => 0,
            'estimated_cost_usd' => 0,
            'cache_hit'          => false,
            'created_at'         => '2026-01-01 00:00:00',
        ],
        [
            'feature_key'        => 'fake.echo',
            'package'            => 'artisanpack-ui/ai-fake',
            'provider'           => 'anthropic',
            'model'              => 'haiku',
            'input_tokens'       => 0,
            'output_tokens'      => 0,
            'estimated_cost_usd' => 0,
            'cache_hit'          => false,
            'created_at'         => '2026-07-04 00:00:00',
        ],
    ] );

    config( [ 'artisanpack.ai.usage.retention_days' => 90 ] );

    $deleted = app( PurgeUsageEventsJob::class )->handle(
        app( Illuminate\Contracts\Config\Repository::class ),
        app( AiUsageRepository::class ),
    );

    expect( $deleted )->toBe( 1 );
    expect( DB::table( 'ai_usage_events' )->count() )->toBe( 1 );

    Carbon::setTestNow();
} );

/**
 * Build a runtime subclass of FakeAgent with distinct feature key + package.
 *
 * @param  string  $key      Feature key.
 * @param  string  $package  Package name.
 *
 * @return class-string<FakeAgent>
 */
function makeAgentClass( string $key, string $package ): string
{
    $suffix    = str_replace( [ '.', '-', '/' ], '_', $key );
    $className = 'RuntimeAgent_' . $suffix;

    if ( ! class_exists( $className ) ) {
        eval(
            'class ' . $className . ' extends Tests\\Support\\FakeAgent {'
            . 'public string $featureKey = ' . var_export( $key, true ) . ';'
            . 'public string $package = ' . var_export( $package, true ) . ';'
            . '}'
        );
    }

    return $className;
}
