<?php

/**
 * Ai service provider.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ai;

use ArtisanPackUI\Ai\Console\Commands\RotateAiCredentialsCommand;
use ArtisanPackUI\Ai\Contracts\CredentialResolver;
use ArtisanPackUI\Ai\Contracts\FeatureRegistry;
use ArtisanPackUI\Ai\Credentials\ChainedCredentialResolver;
use ArtisanPackUI\Ai\Credentials\SettingsCredentialStore;
use ArtisanPackUI\Ai\Events\AgentUsageRecorded;
use ArtisanPackUI\Ai\Listeners\PersistAgentUsage;
use ArtisanPackUI\Ai\Registry\ArrayFeatureRegistry;
use ArtisanPackUI\Ai\Repositories\AiUsageRepository;
use ArtisanPackUI\Ai\Support\AiSettingsRegistrar;
use ArtisanPackUI\Ai\Support\BudgetSettings;
use ArtisanPackUI\Ai\Support\CostEstimator;
use ArtisanPackUI\Ai\Support\FeatureSettings;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use LogicException;

/**
 * Service provider for the Ai package.
 *
 * Wires the frozen contract surface (`ArtisanPackAgent`, `FeatureRegistry`,
 * `CredentialResolver`) into the container, publishes the shared config,
 * auto-discovers features from provider `aiFeatures()` methods, and hooks
 * the cms-framework Settings store when the `settings` table is available.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */
class AiServiceProvider extends ServiceProvider
{
    /**
     * {@inheritDoc}
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/ai.php',
            'artisanpack-ai-temp',
        );

        $this->app->singleton( 'artisanpack.ai', function ( $app ) {
            return new Ai();
        } );

        $this->app->singleton( SettingsCredentialStore::class, function ( $app ) {
            // Resolve the encrypter lazily so a runtime APP_KEY rotation is
            // picked up on the next call — capturing the instance in the
            // constructor would strand this singleton on the old key.
            return new SettingsCredentialStore( fn () => $app->make( Encrypter::class ) );
        } );

        $this->app->singleton( CredentialResolver::class, function ( $app ) {
            $resolver = new ChainedCredentialResolver( $app->make( Repository::class ) );

            $store = $app->make( SettingsCredentialStore::class );

            $resolver->useStore( function ( ?string $featureKey ) use ( $store ) {
                return $store->load();
            } );

            return $resolver;
        } );

        $this->app->singleton( FeatureRegistry::class, function ( $app ) {
            return new ArrayFeatureRegistry(
                $app,
                $app->make( Repository::class ),
                $app->make( CredentialResolver::class ),
            );
        } );

        $this->app->singleton( CostEstimator::class, function ( $app ) {
            return new CostEstimator( $app->make( Repository::class ) );
        } );

        $this->app->singleton( AiUsageRepository::class, function ( $app ) {
            return new AiUsageRepository( $app->make( ConnectionResolverInterface::class ) );
        } );

        $this->app->singleton( BudgetSettings::class, function ( $app ) {
            return new BudgetSettings(
                $app->make( Repository::class ),
                $app->make( ConnectionResolverInterface::class ),
            );
        } );

        $this->app->singleton( FeatureSettings::class, function ( $app ) {
            return new FeatureSettings(
                $app->make( Repository::class ),
                $app->make( ConnectionResolverInterface::class ),
            );
        } );

        $this->app->singleton( AiSettingsRegistrar::class, function ( $app ) {
            return new AiSettingsRegistrar( $app );
        } );

        // Singleton binding so the memoised table probe on the listener
        // survives across every AgentUsageRecorded event within a request
        // / queue worker.
        $this->app->singleton( PersistAgentUsage::class, function ( $app ) {
            return new PersistAgentUsage(
                $app->make( Repository::class ),
                $app->make( CostEstimator::class ),
                $app->make( ConnectionResolverInterface::class ),
            );
        } );
    }

    /**
     * {@inheritDoc}
     */
    public function boot(): void
    {
        $this->mergeConfiguration();

        $this->loadMigrationsFrom( __DIR__ . '/../database/migrations' );
        $this->loadViewsFrom( __DIR__ . '/../resources/views', 'artisanpack-ai' );

        if ( $this->app->runningInConsole() ) {
            $this->publishes( [
                __DIR__ . '/../config/ai.php' => config_path( 'artisanpack/ai.php' ),
            ], 'artisanpack-package-config' );

            // Migrations are auto-loaded via loadMigrationsFrom() above.
            // We intentionally do NOT expose a `vendor:publish` tag for them
            // — publishing a copy while the package's own migrations are
            // still discovered would produce two migrations attempting to
            // create the same table on the next `php artisan migrate`.

            $this->publishes( [
                __DIR__ . '/../resources/views' => resource_path( 'views/vendor/artisanpack-ai' ),
            ], 'artisanpack-ai-views' );

            $this->commands( [
                RotateAiCredentialsCommand::class,
            ] );
        }

        // Only wire the usage-persistence listener when tracking is enabled.
        // Otherwise every AgentUsageRecorded event still resolves the
        // listener from the container and reads config for a no-op.
        if ( (bool) config( 'artisanpack.ai.usage.enabled', true ) ) {
            /** @var Dispatcher $events */
            $events = $this->app->make( Dispatcher::class );
            $events->listen( AgentUsageRecorded::class, [ PersistAgentUsage::class, 'handle' ] );
        }

        $this->wireSettingsToggleStore();
        $this->autoDiscoverFeatures();
        $this->registerCmsFrameworkSettings();
        $this->registerLivewireComponents();
        $this->registerAdminPages();
    }

    /**
     * Register the AI admin Livewire components with Livewire's manager.
     *
     * We resolve `Livewire\Livewire` lazily via `class_exists` because
     * livewire/livewire is only a `require-dev`/`suggest` — apps that don't
     * use Livewire still boot cleanly.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function registerLivewireComponents(): void
    {
        if ( ! class_exists( \Livewire\Livewire::class ) ) {
            return;
        }

        \Livewire\Livewire::component(
            'artisanpack-ai.admin.settings',
            Livewire\Admin\AiSettings::class,
        );
        \Livewire\Livewire::component(
            'artisanpack-ai.admin.usage',
            Livewire\Admin\UsageDashboard::class,
        );
    }

    /**
     * Register the AI settings + usage admin pages under cms-framework's
     * "Packages" nav section when the framework helper is available.
     *
     * The pages themselves are Livewire components — the actions here just
     * render the mount view. Downstream apps that customise the admin nav
     * can suppress this by wrapping the ai boot in their own logic.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function registerAdminPages(): void
    {
        if ( ! function_exists( 'apAddAdminPage' ) || ! function_exists( 'apAddSubAdminPage' ) ) {
            return;
        }

        apAddAdminPage(
            (string) __( 'AI' ),
            'packages/ai',
            'packages',
            [
                'action'     => fn () => view( 'artisanpack-ai::admin.pages.landing' ),
                'icon'       => 'o-sparkles',
                'order'      => 20,
                'capability' => 'manage_ai_settings',
            ],
        );

        apAddSubAdminPage(
            (string) __( 'Settings' ),
            'packages/ai/settings',
            'packages/ai',
            [
                'action'     => fn () => view( 'artisanpack-ai::admin.pages.settings' ),
                'icon'       => 'o-cog-6-tooth',
                'order'      => 1,
                'capability' => 'manage_ai_settings',
            ],
        );

        apAddSubAdminPage(
            (string) __( 'Usage' ),
            'packages/ai/usage',
            'packages/ai',
            [
                'action'     => fn () => view( 'artisanpack-ai::admin.pages.usage' ),
                'icon'       => 'o-chart-bar',
                'order'      => 2,
                'capability' => 'manage_ai_settings',
            ],
        );
    }

    /**
     * Register the four AI setting groups (credentials, features, budget,
     * cache) with cms-framework's SettingsManager, when available.
     *
     * The check is a plain `class_exists()` so we never touch cms-framework
     * classes on stacks where the package isn't installed. Absence isn't
     * an error — every ai setting resolves via env / config fallbacks in
     * that mode.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function registerCmsFrameworkSettings(): void
    {
        if ( ! AiSettingsRegistrar::isCmsFrameworkAvailable() ) {
            return;
        }

        $this->app->make( AiSettingsRegistrar::class )->register();
    }

    /**
     * Merges the package defaults with the user's customizations under
     * `artisanpack.ai`, giving the user's values precedence.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function mergeConfiguration(): void
    {
        $packageDefaults = config( 'artisanpack-ai-temp', [] );
        $userConfig      = config( 'artisanpack.ai', [] );
        $mergedConfig    = array_replace_recursive( $packageDefaults, $userConfig );
        config( [ 'artisanpack.ai' => $mergedConfig ] );
    }

    /**
     * Bind the settings-backed toggle store into the registry, if available.
     *
     * The registry stays functional without cms-framework — it just falls
     * back to config-driven toggles.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function wireSettingsToggleStore(): void
    {
        $registry = $this->app->make( FeatureRegistry::class );

        if ( ! $registry instanceof ArrayFeatureRegistry ) {
            return;
        }

        // Memoise the settings-table probe so we don't hit information_schema
        // on every toggle read; the ai package's own tests recreate the table
        // between cases, so honour a fresh probe when it disappears.
        $tableExists = null;
        $probe       = function () use ( &$tableExists ): bool {
            if ( null === $tableExists ) {
                $tableExists = Schema::hasTable( 'settings' );
            }

            return $tableExists;
        };

        $registry->useToggleStore(
            function ( string $featureKey ) use ( $probe ) {
                if ( ! $probe() ) {
                    return null;
                }

                $value = DB::table( 'settings' )
                    ->where( 'key', 'ai_features.' . $featureKey . '.enabled' )
                    ->value( 'value' );

                if ( null === $value ) {
                    return null;
                }

                // FILTER_NULL_ON_FAILURE means unparseable strings return null
                // and the reader falls through to the in-memory / config
                // default rather than force-disabling the feature.
                $parsed = filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );

                return null === $parsed ? null : $parsed;
            },
            function ( string $featureKey, bool $enabled ) use ( $probe, &$tableExists ): void {
                if ( ! $probe() ) {
                    return;
                }

                DB::table( 'settings' )->updateOrInsert(
                    [ 'key' => 'ai_features.' . $featureKey . '.enabled' ],
                    [ 'value' => $enabled ? '1' : '0', 'type' => 'boolean' ],
                );

                $tableExists = true;
            },
        );
    }

    /**
     * Register features from two sources, in this order:
     *
     *   1. The `ap.ai.register-features` filter hook — the shared
     *      ecosystem-wide extension convention (see artisanpack-ui/icons
     *      `ap.icons.register-icon-sets`). Callbacks receive the
     *      `FeatureRegistry` and register directly against it.
     *   2. A public `aiFeatures(): array` method on any loaded service
     *      provider — the RFC-frozen fallback for packages that ship an
     *      agent alongside a provider.
     *
     * The array shape returned by `aiFeatures()` is
     * `[ featureKey => agentClass ]` or
     * `[ featureKey => [ 'agent' => agentClass, ...meta ] ]`.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function autoDiscoverFeatures(): void
    {
        /** @var FeatureRegistry $registry */
        $registry = $this->app->make( FeatureRegistry::class );

        $this->applyRegisterFeaturesFilter( $registry );
        $this->discoverFeaturesFromProviders( $registry );
    }

    /**
     * Invoke the `ap.ai.register-features` filter when the hooks helper is
     * available.
     *
     * @since 1.0.0
     *
     * @param  FeatureRegistry  $registry  Registry to populate.
     *
     * @return void
     */
    protected function applyRegisterFeaturesFilter( FeatureRegistry $registry ): void
    {
        if ( ! function_exists( 'applyFilters' ) ) {
            return;
        }

        /**
         * Filters the AI feature registry so downstream packages can
         * register their agents without owning a service-provider method.
         *
         * @since 1.0.0
         *
         * @hook  ap.ai.register-features
         *
         * @param  FeatureRegistry  $registry  Registry to populate with `register()` calls.
         *
         * @return FeatureRegistry
         */
        applyFilters( 'ap.ai.register-features', $registry );
    }

    /**
     * Iterate loaded providers and register any features they declare.
     *
     * @since 1.0.0
     *
     * @param  FeatureRegistry  $registry  Registry to populate.
     *
     * @return void
     */
    protected function discoverFeaturesFromProviders( FeatureRegistry $registry ): void
    {
        foreach ( $this->app->getLoadedProviders() as $providerClass => $loaded ) {
            if ( ! $loaded ) {
                continue;
            }

            $provider = $this->app->resolveProvider( $providerClass );

            if ( ! method_exists( $provider, 'aiFeatures' ) ) {
                continue;
            }

            /** @var array<string, mixed> $features */
            $features = $provider->aiFeatures();

            foreach ( $features as $featureKey => $definition ) {
                $this->registerDiscoveredFeature( $registry, (string) $featureKey, $definition );
            }
        }
    }

    /**
     * Normalise and register a single discovered feature entry.
     *
     * @since 1.0.0
     *
     * @param  FeatureRegistry  $registry    Target registry.
     * @param  string           $featureKey  Feature key.
     * @param  mixed            $definition  Raw definition — string agent class or `[ 'agent' => ..., ...meta ]`.
     *
     * @return void
     */
    protected function registerDiscoveredFeature( FeatureRegistry $registry, string $featureKey, mixed $definition ): void
    {
        $agentClass = null;
        $meta       = [];

        if ( is_string( $definition ) ) {
            $agentClass = $definition;
        } elseif ( is_array( $definition ) && isset( $definition['agent'] ) ) {
            $agentClass = (string) $definition['agent'];
            unset( $definition['agent'] );
            $meta = $definition;
        }

        if ( null === $agentClass || '' === $agentClass ) {
            return;
        }

        if ( ! class_exists( $agentClass ) ) {
            throw new LogicException( sprintf(
                'AI feature "%s" declared agent class %s, which does not exist.',
                $featureKey,
                $agentClass,
            ) );
        }

        $registry->register( $featureKey, $agentClass, $meta );
    }
}
