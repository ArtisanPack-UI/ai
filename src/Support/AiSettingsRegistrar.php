<?php

/**
 * Registers AI setting keys with cms-framework's Settings module.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ai\Support;

use ArtisanPackUI\Ai\Contracts\FeatureRegistry;
use ArtisanPackUI\Ai\Registry\FeatureDefinition;
use ArtisanPackUI\CMSFramework\Modules\Settings\Enums\SettingType;
use ArtisanPackUI\CMSFramework\Modules\Settings\Managers\SettingsManager;
use Illuminate\Contracts\Container\Container;

/**
 * Populates cms-framework's SettingsManager with the four AI setting groups
 * described in the RFC:
 *
 *   - `ai_credentials.*` — provider, default model, base URL (the encrypted
 *                          `api_key` is owned by SettingsCredentialStore and
 *                          intentionally NOT registered here).
 *   - `ai_features.*`    — per-feature toggle + model + instructions overrides.
 *                          Registered lazily via the
 *                          `ap.settings.registeredSettings` filter so features
 *                          registered after ai's boot() still show up.
 *   - `ai.monthly_budget_usd` — matches `BudgetSettings::MONTHLY_CAP_KEY`.
 *   - `ai_cache.*`       — informational: cache is config-only at runtime.
 *
 * The keys deliberately match the actual storage prefixes so an admin edit
 * through SettingsManager lands in the same row the runtime reads.
 *
 * Registration is a no-op when cms-framework isn't installed — every caller
 * is guarded by a `class_exists()` check on `SettingsManager` in the ai
 * service provider, so downstream apps that never require cms-framework
 * simply run in env-only mode.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */
final class AiSettingsRegistrar
{
    /**
     * Prefixes used when registering AI settings with SettingsManager.
     *
     * These are aligned with the underlying stores' actual key conventions
     * so a write through cms-framework's SettingsManager lands in the same
     * row that `SettingsCredentialStore`, `FeatureSettings` and
     * `BudgetSettings` read. Previously the registrar advertised
     * dot-separated prefixes (`ai.credentials.*`, `ai.features.*`) while
     * the stores used underscores (`ai_credentials.*`, `ai_features.*`) —
     * so admin edits via SettingsManager were silently ignored by the
     * runtime.
     *
     * @since 1.0.0
     */
    public const CREDENTIALS_PREFIX = 'ai_credentials.';
    public const FEATURES_PREFIX    = 'ai_features.';
    public const BUDGET_PREFIX      = 'ai.';
    public const CACHE_PREFIX       = 'ai_cache.';

    /**
     * Build the registrar.
     *
     * @since 1.0.0
     *
     * @param  Container  $container  Service container.
     */
    public function __construct( protected Container $container )
    {
    }

    /**
     * Whether cms-framework's `SettingsManager` is loadable in the current
     * process.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public static function isCmsFrameworkAvailable(): bool
    {
        return class_exists( SettingsManager::class );
    }

    /**
     * Register every AI setting group against the SettingsManager singleton.
     *
     * Individual keys are registered via `SettingsManager::registerSetting()`
     * so cms-framework's admin can enumerate them, but reads/writes still
     * happen through the ai package's dedicated stores
     * (`SettingsCredentialStore`, `BudgetSettings`, `FeatureSettings`) so
     * encryption and probing semantics stay owned by the ai package.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function register(): void
    {
        if ( ! self::isCmsFrameworkAvailable() ) {
            return;
        }

        /** @var SettingsManager $settings */
        $settings = $this->container->make( SettingsManager::class );

        $this->registerCredentialsGroup( $settings );
        $this->registerFeaturesGroup( $settings );
        $this->registerBudgetGroup( $settings );
        $this->registerCacheGroup( $settings );
    }

    /**
     * Setting keys for the credentials group.
     *
     * The API key itself is never stored via SettingsManager — the sanitizer
     * simply strips whitespace so that even a mis-routed write can't leak
     * plaintext. Real writes go through `SettingsCredentialStore` which
     * encrypts at rest.
     *
     * @since 1.0.0
     *
     * @param  SettingsManager  $settings  Manager to register keys against.
     *
     * @return void
     */
    protected function registerCredentialsGroup( SettingsManager $settings ): void
    {
        $settings->registerSetting(
            self::CREDENTIALS_PREFIX . 'provider',
            'anthropic',
            $this->stringSanitizer(),
            SettingType::String,
        );

        // NOTE: `api_key` is intentionally NOT registered here. The plaintext
        // key is owned by `SettingsCredentialStore` which encrypts at rest,
        // so a write through the generic `SettingsManager` path would
        // corrupt the ciphertext and a read would return unusable data. The
        // admin surface writes go through the store directly.

        $settings->registerSetting(
            self::CREDENTIALS_PREFIX . 'default_model',
            null,
            $this->nullableStringSanitizer(),
            SettingType::String,
        );

        $settings->registerSetting(
            self::CREDENTIALS_PREFIX . 'base_url',
            null,
            $this->nullableStringSanitizer(),
            SettingType::String,
        );
    }

    /**
     * Setting keys for every registered feature (toggle + model + instructions).
     *
     * Instead of iterating the FeatureRegistry at boot() — which would miss
     * every feature registered by a downstream provider whose boot() runs
     * after ours — we hook the `ap.settings.registeredSettings` filter so
     * enumeration happens lazily each time cms-framework asks for the
     * current setting catalog. That means features registered after ai's
     * boot() still show up in the admin the first time it's rendered.
     *
     * @since 1.0.0
     *
     * @param  SettingsManager  $settings  Manager to register keys against.
     *
     * @return void
     */
    protected function registerFeaturesGroup( SettingsManager $settings ): void
    {
        if ( ! function_exists( 'addFilter' ) ) {
            return;
        }

        $container = $this->container;
        $bool      = $this->boolSanitizer();
        $nullable  = $this->nullableStringSanitizer();
        $prefix    = self::FEATURES_PREFIX;

        addFilter(
            'ap.settings.registeredSettings',
            static function ( array $registered ) use ( $container, $bool, $nullable, $prefix ): array {
                if ( ! $container->bound( FeatureRegistry::class ) ) {
                    return $registered;
                }

                /** @var FeatureRegistry $registry */
                $registry = $container->make( FeatureRegistry::class );

                foreach ( $registry->all() as $definition ) {
                    if ( ! $definition instanceof FeatureDefinition ) {
                        continue;
                    }

                    $registered[ $prefix . $definition->featureKey . '.enabled' ] = [
                        'default'  => true,
                        'type'     => SettingType::Boolean,
                        'callback' => $bool,
                    ];

                    $registered[ $prefix . $definition->featureKey . '.model' ] = [
                        'default'  => null,
                        'type'     => SettingType::String,
                        'callback' => $nullable,
                    ];

                    $registered[ $prefix . $definition->featureKey . '.instructions' ] = [
                        'default'  => null,
                        'type'     => SettingType::String,
                        'callback' => $nullable,
                    ];
                }

                return $registered;
            },
        );
    }

    /**
     * Setting keys for the budget group.
     *
     * @since 1.0.0
     *
     * @param  SettingsManager  $settings  Manager to register keys against.
     *
     * @return void
     */
    protected function registerBudgetGroup( SettingsManager $settings ): void
    {
        // Aligned with `BudgetSettings::MONTHLY_CAP_KEY = 'ai.monthly_budget_usd'`
        // so an admin edit via SettingsManager reaches the same row the
        // runtime reads. The warning_percentage field lives only in config
        // (not in the settings table) and is not registered here.
        $settings->registerSetting(
            BudgetSettings::MONTHLY_CAP_KEY,
            null,
            $this->nullableFloatSanitizer(),
            SettingType::Float,
        );
    }

    /**
     * Setting keys for the cache group.
     *
     * @since 1.0.0
     *
     * @param  SettingsManager  $settings  Manager to register keys against.
     *
     * @return void
     */
    protected function registerCacheGroup( SettingsManager $settings ): void
    {
        // Cache settings live entirely in `config/artisanpack/ai.php`
        // (`artisanpack.ai.cache.enabled` / `.ttl`) and are not read from
        // the settings table at runtime. We still surface them so the
        // admin has a visible location — writes will land in the settings
        // table but consumers ignore them. If an app needs runtime-editable
        // cache flags, a downstream package should extend the ai package
        // with a store analogous to `BudgetSettings`.
        $settings->registerSetting(
            self::CACHE_PREFIX . 'enabled',
            (bool) config( 'artisanpack.ai.cache.enabled', false ),
            $this->boolSanitizer(),
            SettingType::Boolean,
        );

        $settings->registerSetting(
            self::CACHE_PREFIX . 'ttl',
            (int) config( 'artisanpack.ai.cache.ttl', 2_592_000 ),
            $this->intSanitizer(),
            SettingType::Integer,
        );
    }

    /**
     * Trim + string cast sanitizer. Empty string is coerced to a non-empty
     * default when the framework hands us null.
     *
     * @since 1.0.0
     *
     * @return callable(mixed): string
     */
    protected function stringSanitizer(): callable
    {
        return static fn ( $value ): string => trim( (string) $value );
    }

    /**
     * Trim + string cast sanitizer that preserves null → null.
     *
     * @since 1.0.0
     *
     * @return callable(mixed): ?string
     */
    protected function nullableStringSanitizer(): callable
    {
        return static function ( $value ): ?string {
            if ( null === $value ) {
                return null;
            }

            $trimmed = trim( (string) $value );

            return '' === $trimmed ? null : $trimmed;
        };
    }

    /**
     * Boolean sanitizer honouring truthy strings ("1", "true", "on").
     *
     * @since 1.0.0
     *
     * @return callable(mixed): bool
     */
    protected function boolSanitizer(): callable
    {
        return static fn ( $value ): bool => (bool) filter_var( $value, FILTER_VALIDATE_BOOLEAN );
    }

    /**
     * Integer sanitizer with a zero fallback.
     *
     * @since 1.0.0
     *
     * @return callable(mixed): int
     */
    protected function intSanitizer(): callable
    {
        return static fn ( $value ): int => (int) $value;
    }

    /**
     * Float sanitizer with a zero fallback.
     *
     * @since 1.0.0
     *
     * @return callable(mixed): float
     */
    protected function floatSanitizer(): callable
    {
        return static fn ( $value ): float => (float) $value;
    }

    /**
     * Nullable float sanitizer — empty string / null returns null.
     *
     * @since 1.0.0
     *
     * @return callable(mixed): ?float
     */
    protected function nullableFloatSanitizer(): callable
    {
        return static function ( $value ): ?float {
            if ( null === $value || '' === $value ) {
                return null;
            }

            return (float) $value;
        };
    }
}
