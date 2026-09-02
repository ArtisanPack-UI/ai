<?php

/**
 * Test-only Livewire component exercising the ChecksFeatureToggle concern.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.2.0
 */

declare( strict_types=1 );

namespace Tests\Support;

use ArtisanPackUI\Ai\Livewire\Concerns\ChecksFeatureToggle;
use Livewire\Component;

/**
 * Minimal component that declares a `$featureKey` and defers its enabled
 * state to the {@see ChecksFeatureToggle} concern so the trait's computed
 * property can be asserted under `Livewire::test()`.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.2.0
 */
class FeatureToggleComponent extends Component
{
    use ChecksFeatureToggle;

    /**
     * Feature key this component gates on.
     *
     * @since 1.2.0
     *
     * @var string
     */
    protected string $featureKey = 'fake.echo';

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
            <div>{{ $this->isEnabled ? 'feature-on' : 'feature-off' }}</div>
            HTML;
    }
}
