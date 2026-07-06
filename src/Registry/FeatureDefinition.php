<?php

/**
 * Feature definition value object.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ai\Registry;

/**
 * Immutable descriptor of a registered AI feature.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */
final class FeatureDefinition
{
    /**
     * Build the definition.
     *
     * @since 1.0.0
     *
     * @param  string                $featureKey  Feature key (dot notation).
     * @param  class-string          $agentClass  Agent class implementing the feature.
     * @param  string                $package     Owning package name.
     * @param  string|null           $label       Human-readable label.
     * @param  string|null           $description Human-readable description.
     * @param  array<string, mixed>  $meta        Full metadata array.
     */
    public function __construct(
        public readonly string $featureKey,
        public readonly string $agentClass,
        public readonly string $package,
        public readonly ?string $label = null,
        public readonly ?string $description = null,
        public readonly array $meta = [],
    ) {
    }

    /**
     * Build a definition from a metadata array.
     *
     * @since 1.0.0
     *
     * @param  string                $featureKey  Feature key.
     * @param  class-string          $agentClass  Agent class.
     * @param  array<string, mixed>  $meta        Raw metadata.
     *
     * @return self
     */
    public static function fromMeta( string $featureKey, string $agentClass, array $meta ): self
    {
        return new self(
            featureKey: $featureKey,
            agentClass: $agentClass,
            package: (string) ( $meta['package'] ?? self::inferPackageFromClass( $agentClass ) ),
            label: isset( $meta['label'] ) ? (string) $meta['label'] : null,
            description: isset( $meta['description'] ) ? (string) $meta['description'] : null,
            meta: $meta,
        );
    }

    /**
     * Best-effort package name from an agent class namespace.
     *
     * @since 1.0.0
     *
     * @param  class-string  $agentClass  Agent class name.
     *
     * @return string
     */
    protected static function inferPackageFromClass( string $agentClass ): string
    {
        $parts = explode( '\\', $agentClass );

        if ( count( $parts ) >= 2 ) {
            return strtolower( $parts[0] . '/' . $parts[1] );
        }

        return 'unknown';
    }
}
