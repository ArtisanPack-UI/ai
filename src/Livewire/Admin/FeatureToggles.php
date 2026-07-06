<?php

/**
 * Per-feature toggles admin surface (Livewire).
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ai\Livewire\Admin;

use ArtisanPackUI\Ai\Contracts\FeatureRegistry;
use ArtisanPackUI\Ai\Registry\FeatureDefinition;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Lists every registered AI feature grouped by package and lets an
 * administrator flip each one on or off. Toggle state persists through the
 * `FeatureRegistry` (which is wired to cms-framework's Settings store when
 * available and falls back to config otherwise).
 *
 * The listing is driven entirely by the registry — new agents surface here
 * automatically the moment their owning package is loaded.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */
class FeatureToggles extends Component
{
    /**
     * Live search filter matched against feature key, label, and package.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public string $search = '';

    /**
     * Toast state for the current render (success/error/info).
     *
     * @since 1.0.0
     *
     * @var array{ type: string, message: string }|null
     */
    #[Locked]
    public ?array $toast = null;

    /**
     * Toggle a single feature on or off.
     *
     * @since 1.0.0
     *
     * @param  string  $featureKey  Feature key to toggle.
     *
     * @return void
     */
    public function toggle( string $featureKey ): void
    {
        $registry = $this->registry();

        if ( null === $registry->get( $featureKey ) ) {
            $this->toast = [
                'type'    => 'error',
                'message' => (string) __( 'Unknown feature.' ),
            ];

            return;
        }

        if ( $registry->isToggleOn( $featureKey ) ) {
            $registry->disable( $featureKey );
            $message = (string) __( 'Feature ":key" disabled.', [ 'key' => $featureKey ] );
        } else {
            $registry->enable( $featureKey );
            $message = (string) __( 'Feature ":key" enabled.', [ 'key' => $featureKey ] );
        }

        $this->toast = [
            'type'    => 'success',
            'message' => $message,
        ];
    }

    /**
     * Bulk-enable every feature belonging to a package.
     *
     * @since 1.0.0
     *
     * @param  string  $package  Package identifier as reported by the registry.
     *
     * @return void
     */
    public function enablePackage( string $package ): void
    {
        $touched = $this->bulkSet( $package, true );

        if ( 0 === $touched ) {
            $this->toast = [
                'type'    => 'info',
                'message' => (string) __( 'No features found for :package.', [ 'package' => $package ] ),
            ];

            return;
        }

        $this->toast = [
            'type'    => 'success',
            'message' => (string) __( 'All features enabled for :package.', [ 'package' => $package ] ),
        ];
    }

    /**
     * Bulk-disable every feature belonging to a package.
     *
     * @since 1.0.0
     *
     * @param  string  $package  Package identifier as reported by the registry.
     *
     * @return void
     */
    public function disablePackage( string $package ): void
    {
        $touched = $this->bulkSet( $package, false );

        if ( 0 === $touched ) {
            $this->toast = [
                'type'    => 'info',
                'message' => (string) __( 'No features found for :package.', [ 'package' => $package ] ),
            ];

            return;
        }

        $this->toast = [
            'type'    => 'success',
            'message' => (string) __( 'All features disabled for :package.', [ 'package' => $package ] ),
        ];
    }

    /**
     * Grouped feature listing keyed by package.
     *
     * Each package entry contains its `enabled_count` and `total_count`
     * pre-computed so the view can render bulk toggle affordances without
     * counting rows a second time.
     *
     * @since 1.0.0
     *
     * @return list<array{
     *     package: string,
     *     enabled_count: int,
     *     total_count: int,
     *     features: list<array{ key: string, label: string, description: string, enabled: bool }>,
     * }>
     */
    #[Computed]
    public function groupedFeatures(): array
    {
        $registry = $this->registry();
        $needle   = trim( strtolower( $this->search ) );

        $groups = [];

        /** @var FeatureDefinition $definition */
        foreach ( $registry->all() as $definition ) {
            $label       = $definition->label ?? $definition->featureKey;
            $description = $definition->description ?? '';

            if ( '' !== $needle && ! $this->matchesSearch( $definition, $label, $needle ) ) {
                continue;
            }

            $enabled = $registry->isToggleOn( $definition->featureKey );

            $groups[ $definition->package ][] = [
                'key'         => $definition->featureKey,
                'label'       => (string) $label,
                'description' => (string) $description,
                'enabled'     => $enabled,
            ];
        }

        ksort( $groups );

        $result = [];

        foreach ( $groups as $package => $features ) {
            $enabledCount = 0;

            foreach ( $features as $feature ) {
                if ( $feature['enabled'] ) {
                    ++$enabledCount;
                }
            }

            $result[] = [
                'package'       => (string) $package,
                'enabled_count' => $enabledCount,
                'total_count'   => count( $features ),
                'features'      => $features,
            ];
        }

        return $result;
    }

    /**
     * Render the view.
     *
     * @since 1.0.0
     *
     * @return View
     */
    public function render(): View
    {
        return view( 'artisanpack-ai::admin.livewire.feature-toggles' );
    }

    /**
     * Apply an enable/disable to every feature in a package.
     *
     * @since 1.0.0
     *
     * @param  string  $package  Package identifier.
     * @param  bool    $enabled  Desired state.
     *
     * @return int Number of features whose toggle was written.
     */
    protected function bulkSet( string $package, bool $enabled ): int
    {
        $registry = $this->registry();
        $touched  = 0;

        /** @var FeatureDefinition $definition */
        foreach ( $registry->all() as $definition ) {
            if ( $definition->package !== $package ) {
                continue;
            }

            if ( $enabled ) {
                $registry->enable( $definition->featureKey );
            } else {
                $registry->disable( $definition->featureKey );
            }

            ++$touched;
        }

        return $touched;
    }

    /**
     * Test whether a definition matches the current search needle.
     *
     * @since 1.0.0
     *
     * @param  FeatureDefinition  $definition  Feature definition.
     * @param  string             $label       Effective display label.
     * @param  string             $needle      Pre-lowercased search string.
     *
     * @return bool
     */
    protected function matchesSearch( FeatureDefinition $definition, string $label, string $needle ): bool
    {
        $haystacks = [
            strtolower( $definition->featureKey ),
            strtolower( $label ),
            strtolower( $definition->package ),
            strtolower( (string) ( $definition->description ?? '' ) ),
        ];

        foreach ( $haystacks as $haystack ) {
            if ( '' !== $haystack && false !== strpos( $haystack, $needle ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve the registry lazily so tests that swap it via the container
     * pick up the updated binding on every action.
     *
     * @since 1.0.0
     *
     * @return FeatureRegistry
     */
    protected function registry(): FeatureRegistry
    {
        return app( FeatureRegistry::class );
    }
}
