<?php

/**
 * Array-backed feature registry.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ai\Registry;

use ArtisanPackUI\Ai\Contracts\CredentialResolver;
use ArtisanPackUI\Ai\Contracts\FeatureRegistry;
use ArtisanPackUI\Ai\Events\FeatureDisabled;
use ArtisanPackUI\Ai\Events\FeatureEnabled;
use ArtisanPackUI\Ai\Events\FeatureRegistered;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Collection;
use LogicException;

/**
 * In-memory catalog of AI features with a pluggable persistence hook for
 * toggle state.
 *
 * The registry is the source of truth for `isEnabled()`. Toggle state is
 * read from an injected callable (usually cms-framework `Settings`) when
 * present, and falls back to the `artisanpack.ai.features.<key>.enabled`
 * config value.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */
class ArrayFeatureRegistry implements FeatureRegistry
{
    /**
     * Registered feature definitions keyed by feature key.
     *
     * @since 1.0.0
     *
     * @var array<string, FeatureDefinition>
     */
    protected array $features = [];

    /**
     * In-memory toggle overrides (used when no persistent store is bound).
     *
     * @since 1.0.0
     *
     * @var array<string, bool>
     */
    protected array $toggles = [];

    /**
     * Optional store used to read persisted toggle state.
     *
     * Signature: `fn( string $featureKey ): ?bool`.
     *
     * @since 1.0.0
     *
     * @var (callable( string ): ?bool)|null
     */
    protected $toggleReader;

    /**
     * Optional store used to write persisted toggle state.
     *
     * Signature: `fn( string $featureKey, bool $enabled ): void`.
     *
     * @since 1.0.0
     *
     * @var (callable( string, bool ): void)|null
     */
    protected $toggleWriter;

    /**
     * Build the registry.
     *
     * @since 1.0.0
     *
     * @param  Container           $container   Container used to resolve the current dispatcher lazily.
     * @param  Repository          $config      Config repository.
     * @param  CredentialResolver  $credentials Credential resolver used to short-circuit `isEnabled()`.
     */
    public function __construct(
        protected Container $container,
        protected Repository $config,
        protected CredentialResolver $credentials,
    ) {
    }

    /**
     * Bind the persistent toggle store.
     *
     * @since 1.0.0
     *
     * @param  (callable( string ): ?bool)      $reader  Read callback.
     * @param  (callable( string, bool ): void) $writer  Write callback.
     *
     * @return void
     */
    public function useToggleStore( callable $reader, callable $writer ): void
    {
        $this->toggleReader = $reader;
        $this->toggleWriter = $writer;
    }

    /**
     * {@inheritDoc}
     */
    public function register( string $featureKey, string $agentClass, array $meta = [] ): void
    {
        if ( isset( $this->features[ $featureKey ] ) ) {
            $existing = $this->features[ $featureKey ];

            if ( $existing->agentClass === $agentClass ) {
                return;
            }

            throw new LogicException( sprintf(
                'AI feature "%s" is already registered by %s (package %s); refusing to overwrite with %s.',
                $featureKey,
                $existing->agentClass,
                $existing->package,
                $agentClass,
            ) );
        }

        $definition                     = FeatureDefinition::fromMeta( $featureKey, $agentClass, $meta );
        $this->features[ $featureKey ]  = $definition;

        $this->dispatcher()->dispatch( new FeatureRegistered( $definition ) );
    }

    /**
     * {@inheritDoc}
     */
    public function all(): Collection
    {
        return Collection::make( $this->features )
            ->sortBy( fn ( FeatureDefinition $definition ): string => $definition->package . '::' . $definition->featureKey )
            ->values();
    }

    /**
     * {@inheritDoc}
     */
    public function get( string $featureKey ): ?FeatureDefinition
    {
        return $this->features[ $featureKey ] ?? null;
    }

    /**
     * {@inheritDoc}
     */
    public function isEnabled( string $featureKey ): bool
    {
        if ( ! $this->isToggleOn( $featureKey ) ) {
            return false;
        }

        if ( ! $this->credentials->hasAny() ) {
            return false;
        }

        return true;
    }

    /**
     * Determine whether the toggle state alone is on, ignoring credentials.
     *
     * Useful in call sites that want to separate the "administrator disabled
     * this feature" case from the "credentials missing" case.
     *
     * @since 1.0.0
     *
     * @param  string  $featureKey  Feature key to check.
     *
     * @return bool True when the feature is registered and toggle=on.
     */
    public function isToggleOn( string $featureKey ): bool
    {
        if ( ! isset( $this->features[ $featureKey ] ) ) {
            return false;
        }

        return true === $this->readToggle( $featureKey );
    }

    /**
     * {@inheritDoc}
     */
    public function enable( string $featureKey ): void
    {
        $this->writeToggle( $featureKey, true );
        $this->dispatcher()->dispatch( new FeatureEnabled( $featureKey ) );
    }

    /**
     * {@inheritDoc}
     */
    public function disable( string $featureKey ): void
    {
        $this->writeToggle( $featureKey, false );
        $this->dispatcher()->dispatch( new FeatureDisabled( $featureKey ) );
    }

    /**
     * Resolve the current event dispatcher lazily so that late `Event::fake()`
     * calls in tests take effect.
     *
     * @since 1.0.0
     *
     * @return Dispatcher
     */
    protected function dispatcher(): Dispatcher
    {
        return $this->container->make( Dispatcher::class );
    }

    /**
     * Read the current toggle state, applying store → in-memory → config
     * precedence.
     *
     * @since 1.0.0
     *
     * @param  string  $featureKey  Feature key to read.
     *
     * @return bool True when the feature is enabled.
     */
    protected function readToggle( string $featureKey ): bool
    {
        if ( null !== $this->toggleReader ) {
            $stored = ( $this->toggleReader )( $featureKey );

            if ( null !== $stored ) {
                return (bool) $stored;
            }
        }

        if ( array_key_exists( $featureKey, $this->toggles ) ) {
            return $this->toggles[ $featureKey ];
        }

        $features = $this->config->get( 'artisanpack.ai.features', [] );

        if ( is_array( $features ) && isset( $features[ $featureKey ]['enabled'] ) ) {
            return (bool) $features[ $featureKey ]['enabled'];
        }

        return true;
    }

    /**
     * Persist the toggle state via the bound writer or in-memory fallback.
     *
     * @since 1.0.0
     *
     * @param  string  $featureKey  Feature key to write.
     * @param  bool    $enabled     Desired state.
     *
     * @return void
     */
    protected function writeToggle( string $featureKey, bool $enabled ): void
    {
        $this->toggles[ $featureKey ] = $enabled;

        if ( null !== $this->toggleWriter ) {
            ( $this->toggleWriter )( $featureKey, $enabled );
        }
    }
}
