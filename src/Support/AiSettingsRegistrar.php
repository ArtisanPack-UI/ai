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
use ArtisanPackUI\Ai\Credentials\SettingsCredentialStore;
use ArtisanPackUI\Ai\Registry\FeatureDefinition;
use ArtisanPackUI\CMSFramework\Modules\Settings\Enums\SettingType;
use ArtisanPackUI\CMSFramework\Modules\Settings\Managers\SettingsManager;
use Illuminate\Contracts\Container\Container;

/**
 * Populates cms-framework's SettingsManager with the four AI setting groups
 * described in the RFC:
 *
 *   - `ai.credentials.*`  — provider, API key (encrypted), default model, base URL
 *   - `ai.features.*`     — per-feature toggle + model + instructions overrides
 *   - `ai.budget.*`       — monthly cap + warning threshold
 *   - `ai.cache.*`        — enabled + TTL
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
     * Prefix used for keys that show up in the cms-framework admin.
     *
     * These are the *public* keys registered against `SettingsManager`. They
     * intentionally use dot notation so the framework can lay them out under
     * an "AI" section on its admin nav. The credentials store separately
     * writes encrypted rows under the legacy `ai_credentials.*` /
     * `ai_features.*` prefixes; those are private to the store.
     *
     * @since 1.0.0
     */
    public const CREDENTIALS_PREFIX = 'ai.credentials.';
    public const FEATURES_PREFIX    = 'ai.features.';
    public const BUDGET_PREFIX      = 'ai.budget.';
    public const CACHE_PREFIX       = 'ai.cache.';

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

        $settings->registerSetting(
            self::CREDENTIALS_PREFIX . 'api_key',
            '',
            static fn ( $value ): string => '' === (string) $value ? '' : SettingsCredentialStore::REDACTED_MARKER,
            SettingType::String,
        );

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
     * We iterate the ai FeatureRegistry so cms-framework's admin can render
     * a row per feature without the ai package hard-coding known feature
     * keys. New downstream packages that register a feature after boot are
     * expected to run this registration in their own service provider's
     * `boot()`; the RFC's ecosystem convention lives on the
     * `ap.ai.register-features` filter.
     *
     * @since 1.0.0
     *
     * @param  SettingsManager  $settings  Manager to register keys against.
     *
     * @return void
     */
    protected function registerFeaturesGroup( SettingsManager $settings ): void
    {
        if ( ! $this->container->bound( FeatureRegistry::class ) ) {
            return;
        }

        /** @var FeatureRegistry $registry */
        $registry = $this->container->make( FeatureRegistry::class );

        foreach ( $registry->all() as $definition ) {
            if ( ! $definition instanceof FeatureDefinition ) {
                continue;
            }

            $settings->registerSetting(
                self::FEATURES_PREFIX . $definition->featureKey . '.enabled',
                true,
                $this->boolSanitizer(),
                SettingType::Boolean,
            );

            $settings->registerSetting(
                self::FEATURES_PREFIX . $definition->featureKey . '.model',
                null,
                $this->nullableStringSanitizer(),
                SettingType::String,
            );

            $settings->registerSetting(
                self::FEATURES_PREFIX . $definition->featureKey . '.instructions',
                null,
                $this->nullableStringSanitizer(),
                SettingType::String,
            );
        }
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
        $settings->registerSetting(
            self::BUDGET_PREFIX . 'monthly_usd',
            null,
            $this->nullableFloatSanitizer(),
            SettingType::Float,
        );

        $settings->registerSetting(
            self::BUDGET_PREFIX . 'warning_percentage',
            80.0,
            $this->floatSanitizer(),
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
        $settings->registerSetting(
            self::CACHE_PREFIX . 'enabled',
            false,
            $this->boolSanitizer(),
            SettingType::Boolean,
        );

        $settings->registerSetting(
            self::CACHE_PREFIX . 'ttl',
            2_592_000,
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
