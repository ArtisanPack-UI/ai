<?php

/**
 * Test-only Livewire component exercising the InteractsWithAiFeature concern.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.2.0
 */

declare( strict_types=1 );

namespace Tests\Support;

use ArtisanPackUI\Ai\Exceptions\BudgetExceededException;
use ArtisanPackUI\Ai\Exceptions\FeatureDisabledException;
use ArtisanPackUI\Ai\Exceptions\FeatureError;
use ArtisanPackUI\Ai\Exceptions\MissingCredentialsException;
use ArtisanPackUI\Ai\Livewire\Concerns\InteractsWithAiFeature;
use Livewire\Component;
use RuntimeException;

/**
 * Minimal component that drives {@see InteractsWithAiFeature::runAiFeature()}
 * through both its success and failure branches so the shared ladder can be
 * asserted under `Livewire::test()`.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.2.0
 */
class InteractsWithAiFeatureComponent extends Component
{
    use InteractsWithAiFeature;

    /**
     * Output field a successful run populates.
     *
     * @since 1.2.0
     *
     * @var string
     */
    public string $result = '';

    /**
     * Run a callback that succeeds and assigns component output.
     *
     * @since 1.2.0
     *
     * @return void
     */
    public function succeed(): void
    {
        $this->result = '';

        $this->runAiFeature( function (): void {
            $this->result = 'ok';
        } );
    }

    /**
     * Run a callback that throws the AI exception mapped to `$type`.
     *
     * @since 1.2.0
     *
     * @param  string  $type  One of disabled|credentials|feature|budget|generic.
     *
     * @return void
     */
    public function fail( string $type ): void
    {
        $this->runAiFeature( function () use ( $type ): void {
            throw match ( $type ) {
                'disabled'    => FeatureDisabledException::forFeature( 'fake.echo' ),
                'credentials' => MissingCredentialsException::forFeature( 'fake.echo' ),
                'feature'     => new FeatureError( 'A domain-specific failure reason.' ),
                'budget'      => BudgetExceededException::forFeature( 'fake.echo', 120.0, 100.0 ),
                default       => new RuntimeException( 'unexpected explosion' ),
            };
        } );
    }

    /**
     * Render the component.
     *
     * @since 1.2.0
     *
     * @return string
     */
    public function render(): string
    {
        return <<<'HTML'
            <div>{{ $error ?? '' }}{{ $result }}</div>
            HTML;
    }
}
