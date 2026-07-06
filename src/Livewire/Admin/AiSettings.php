<?php

/**
 * AI Settings admin surface (Livewire).
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ai\Livewire\Admin;

use ArtisanPackUI\Ai\Contracts\FeatureRegistry;
use ArtisanPackUI\Ai\Credentials\Credentials;
use ArtisanPackUI\Ai\Credentials\SettingsCredentialStore;
use ArtisanPackUI\Ai\Registry\FeatureDefinition;
use ArtisanPackUI\Ai\Support\ConnectionTester;
use ArtisanPackUI\Ai\Support\FeatureSettings;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Primary admin surface for configuring the shared AI foundation.
 *
 * Renders under cms-framework's admin nav at `Admin → Packages → AI →
 * Settings`. Users select a provider, drop in credentials, and optionally
 * override the model / instructions per registered feature. The
 * "Test connection" action delegates to `ConnectionTester` so save-time
 * validation matches what the runtime actually reaches for.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */
class AiSettings extends Component
{
    /**
     * Selected provider slug.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public string $provider = 'anthropic';

    /**
     * API key, treated as write-only. `null` means "leave the stored key
     * alone"; empty string means "clear it."
     *
     * @since 1.0.0
     *
     * @var string|null
     */
    public ?string $apiKey = null;

    /**
     * Whether the store currently holds an encrypted API key. Shown in the
     * placeholder ("••••••••") so admins know a value exists.
     *
     * @since 1.0.0
     *
     * @var bool
     */
    #[Locked]
    public bool $apiKeyPresent = false;

    /**
     * Base URL — required for Ollama, optional for anyone else.
     *
     * @since 1.0.0
     *
     * @var string|null
     */
    public ?string $baseUrl = null;

    /**
     * Default model applied when a feature has no per-feature override.
     *
     * @since 1.0.0
     *
     * @var string|null
     */
    public ?string $defaultModel = null;

    /**
     * Per-feature override map.
     *
     * Shape: `[ 'feature.key' => [ 'model' => string|null, 'instructions' => string|null ] ]`.
     *
     * @since 1.0.0
     *
     * @var array<string, array{ model: string|null, instructions: string|null }>
     */
    public array $featureOverrides = [];

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
     * List of provider slugs shown in the dropdown.
     *
     * @since 1.0.0
     *
     * @return list<array{ slug: string, label: string, requires_base_url: bool, requires_api_key: bool }>
     */
    public function providersList(): array
    {
        /** @var ConfigRepository $config */
        $config = app( ConfigRepository::class );

        $providers = (array) $config->get( 'artisanpack.ai.providers', [] );

        $list = [];

        foreach ( array_keys( $providers ) as $slug ) {
            $isOllama = 'ollama' === $slug;

            $list[] = [
                'slug'              => (string) $slug,
                'label'             => ucfirst( (string) $slug ),
                'requires_base_url' => $isOllama,
                'requires_api_key'  => ! $isOllama,
            ];
        }

        return $list;
    }

    /**
     * All registered features, used to render the advanced overrides tab.
     *
     * @since 1.0.0
     *
     * @return list<array{ key: string, package: string, default_model: string }>
     */
    public function featuresList(): array
    {
        /** @var FeatureRegistry $registry */
        $registry = app( FeatureRegistry::class );

        return $registry->all()
            ->map( fn ( FeatureDefinition $definition ): array => [
                'key'           => $definition->featureKey,
                'package'       => $definition->package,
                'default_model' => $definition->defaultModel ?? '',
            ] )
            ->all();
    }

    /**
     * Populate the form from the persisted credential + feature settings.
     *
     * @since 1.0.0
     *
     * @param  SettingsCredentialStore  $store            Credential store.
     * @param  FeatureSettings          $featureSettings  Per-feature override store.
     *
     * @return void
     */
    public function mount( SettingsCredentialStore $store, FeatureSettings $featureSettings ): void
    {
        $public              = $store->toPublicArray();
        $this->provider      = null === $public['provider'] ? $this->provider : (string) $public['provider'];
        $this->apiKeyPresent = (bool) $public['api_key_present'];
        $this->baseUrl       = $public['base_url'];
        $this->defaultModel  = $public['default_model'];

        $this->featureOverrides = $featureSettings->all();
    }

    /**
     * Validation rules — provider is always required, other fields are
     * conditional. We validate API key at save time (in `save()`) rather
     * than declaring it required here because it's write-only — the user
     * may leave it blank to keep the existing stored key.
     *
     * @since 1.0.0
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'provider'                        => [ 'required', 'string' ],
            'baseUrl'                         => [ 'nullable', 'string', 'max:2048' ],
            'defaultModel'                    => [ 'nullable', 'string', 'max:255' ],
            'featureOverrides.*.model'        => [ 'nullable', 'string', 'max:255' ],
            'featureOverrides.*.instructions' => [ 'nullable', 'string', 'max:20000' ],
        ];
    }

    /**
     * Persist credentials and per-feature overrides.
     *
     * @since 1.0.0
     *
     * @param  SettingsCredentialStore  $store            Credential store.
     * @param  FeatureSettings          $featureSettings  Per-feature override store.
     *
     * @return void
     */
    public function save( SettingsCredentialStore $store, FeatureSettings $featureSettings ): void
    {
        $this->validate();

        $isOllama = 'ollama' === $this->provider;

        if ( $isOllama && ( null === $this->baseUrl || '' === trim( (string) $this->baseUrl ) ) ) {
            $this->addError( 'baseUrl', __( 'Ollama requires a base URL.' ) );

            return;
        }

        if ( ! $isOllama && ! $this->apiKeyPresent && ( null === $this->apiKey || '' === $this->apiKey ) ) {
            $this->addError( 'apiKey', __( 'An API key is required for :provider.', [ 'provider' => $this->provider ] ) );

            return;
        }

        // Preserve the existing key when the field is left blank — only
        // overwrite if the admin explicitly typed a new value or explicitly
        // cleared it (empty string after having entered something is rare
        // enough that a "clear" checkbox would be over-engineered here).
        $apiKeyToStore = $this->apiKey;

        if ( null === $apiKeyToStore ) {
            if ( ! $this->apiKeyPresent && ! $isOllama ) {
                $this->addError( 'apiKey', __( 'An API key is required.' ) );

                return;
            }

            $existing = $store->load();

            if ( null === $existing && ! $isOllama ) {
                $this->addError( 'apiKey', __( 'An API key is required.' ) );

                return;
            }

            $apiKeyToStore = null === $existing ? '' : $existing->apiKey;
        }

        $store->save( new Credentials(
            provider: $this->provider,
            apiKey: (string) $apiKeyToStore,
            defaultModel: $this->normaliseNullable( $this->defaultModel ),
            baseUrl: $this->normaliseNullable( $this->baseUrl ),
        ) );

        foreach ( $this->featureOverrides as $featureKey => $override ) {
            $featureSettings->setModel( (string) $featureKey, $this->normaliseNullable( $override['model'] ?? null ) );
            $featureSettings->setInstructions( (string) $featureKey, $this->normaliseNullable( $override['instructions'] ?? null ) );
        }

        // Re-read the store to refresh the "•••••" indicator.
        $public              = $store->toPublicArray();
        $this->apiKeyPresent = (bool) $public['api_key_present'];
        $this->apiKey        = null;

        $this->toast = [
            'type'    => 'success',
            'message' => (string) __( 'AI settings saved.' ),
        ];
    }

    /**
     * Run a lightweight connection test against the currently entered
     * credentials.
     *
     * @since 1.0.0
     *
     * @param  ConnectionTester         $tester  Connection tester.
     * @param  SettingsCredentialStore  $store   Credential store (used to
     *                                           reuse the existing key when
     *                                           the API-key field is blank).
     *
     * @return void
     */
    public function testConnection( ConnectionTester $tester, SettingsCredentialStore $store ): void
    {
        $apiKey = $this->apiKey;

        if ( null === $apiKey ) {
            $existing = $store->load();
            $apiKey   = null === $existing ? '' : $existing->apiKey;
        }

        $credentials = new Credentials(
            provider: $this->provider,
            apiKey: (string) $apiKey,
            defaultModel: $this->normaliseNullable( $this->defaultModel ),
            baseUrl: $this->normaliseNullable( $this->baseUrl ),
        );

        $result = $tester->test( $credentials );

        $this->toast = [
            'type'    => ConnectionTester::RESULT_OK === $result['result'] ? 'success' : 'error',
            'message' => $result['message'],
        ];
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
        return view( 'artisanpack-ai::admin.livewire.ai-settings', [
            'providers' => $this->providersList(),
            'features'  => $this->featuresList(),
        ] );
    }

    /**
     * Coerce a nullable string form value into null when empty.
     *
     * @since 1.0.0
     *
     * @param  string|null  $value  Raw value.
     *
     * @return string|null
     */
    protected function normaliseNullable( ?string $value ): ?string
    {
        if ( null === $value ) {
            return null;
        }

        $trimmed = trim( $value );

        return '' === $trimmed ? null : $trimmed;
    }
}
