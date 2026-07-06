<?php

declare( strict_types=1 );

use ArtisanPackUI\Ai\Contracts\FeatureRegistry;
use Illuminate\Support\ServiceProvider;
use Tests\Support\FakeAgent;

it( 'auto-discovers features from provider aiFeatures() methods', function (): void {
    app()->register( FakePackageProvider::class );

    // Re-run boot on the ai provider so it can pick up FakePackageProvider.
    ( new ArtisanPackUI\Ai\AiServiceProvider( app() ) )->boot();

    /** @var FeatureRegistry $registry */
    $registry = app( FeatureRegistry::class );

    $definition = $registry->get( 'fake.echo' );

    expect( $definition )->not->toBeNull();
    expect( $definition->agentClass )->toBe( FakeAgent::class );
    expect( $definition->package )->toBe( 'artisanpack-ui/fake-package' );
} );

class FakePackageProvider extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(): void
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function aiFeatures(): array
    {
        return [
            'fake.echo' => [
                'agent'   => FakeAgent::class,
                'package' => 'artisanpack-ui/fake-package',
                'label'   => 'Echo Test',
            ],
        ];
    }
}
