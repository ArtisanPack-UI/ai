<?php

declare( strict_types=1 );

namespace Tests;

use ArtisanPackUI\Ai\AiServiceProvider;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use Orchestra\Testbench\TestCase as BaseTestCase;

/**
 * Base Test Case
 *
 * Provides base functionality for all Ai package tests.
 *
 * @since 1.0.0
 */
abstract class TestCase extends BaseTestCase
{
    /**
     * Setup the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // The Livewire admin blade views reference `<x-artisanpack-*>`
        // components that ship in `artisanpack-ui/livewire-ui-components`
        // — not a hard dep of this package. Register minimal anonymous
        // stubs so `Livewire::test()` can render without exploding on
        // "component not found."
        View::addNamespace( 'ai-test-stubs', __DIR__ . '/Support/views' );
        Blade::anonymousComponentPath(
            __DIR__ . '/Support/views/components',
        );

        // Real component ships @scope / @endscope directives from
        // livewire-ui-components. Register no-op fallbacks so rendered
        // views compile even without that package installed.
        $directives = Blade::getCustomDirectives();

        if ( ! isset( $directives['scope'] ) ) {
            // Skip the body entirely — the real `@scope` block references
            // per-row variables that don't exist outside livewire-ui-
            // components' scope wrapper. Not running the body is enough
            // for our tests: they assert on component state, not markup.
            Blade::directive( 'scope', fn ( string $expression ): string => '<?php if ( false ): ?>' );
            Blade::directive( 'endscope', fn (): string => '<?php endif; ?>' );
        }
    }

    /**
     * Create the test-only `settings` table used by the credential store
     * and toggle store tests.
     *
     * Call from an individual test with `$this->createSettingsTable()` when
     * cms-framework Settings semantics are needed.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function createSettingsTable(): void
    {
        ( new Support\Migrations\CreateSettingsTable() )->up();
    }

    /**
     * Gets package providers.
     *
     * Registers Livewire's service provider when livewire/livewire is
     * installed so the Livewire admin components can be exercised under
     * `Livewire\Livewire::test()`. Kept optional so the ai package continues
     * to boot cleanly when Livewire is absent.
     *
     * @since 1.0.0
     *
     * @param  \Illuminate\Foundation\Application  $app  The application instance.
     *
     * @return array<int, class-string> Array of service provider class names.
     */
    protected function getPackageProviders( $app ): array
    {
        $providers = [
            AiServiceProvider::class,
        ];

        if ( class_exists( \Livewire\LivewireServiceProvider::class ) ) {
            array_unshift( $providers, \Livewire\LivewireServiceProvider::class );
        }

        return $providers;
    }

    /**
     * Defines environment setup.
     *
     * @since 1.0.0
     *
     * @param  \Illuminate\Foundation\Application  $app  The application instance.
     */
    protected function defineEnvironment( $app ): void
    {
        $app['config']->set( 'app.key', 'base64:' . base64_encode( random_bytes( 32 ) ) );

        $app['config']->set( 'database.default', 'testbench' );
        $app['config']->set( 'database.connections.testbench', [
            'driver'                  => 'sqlite',
            'database'                => ':memory:',
            'prefix'                  => '',
            'foreign_key_constraints' => true,
        ] );
    }
}
