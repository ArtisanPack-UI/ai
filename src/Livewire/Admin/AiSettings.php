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
     * Per-feature override list, indexed by ordinal position.
     *
     * Livewire 3 splits every `.` in a `wire:model` path into a nested
     * segment, so if we keyed this array by feature key like
     * `seo.suggest_meta_description` the client-side write would land at
     * `featureOverrides['seo']['suggest_meta_description']['model']`
     * instead of the flat key we expect. To sidestep the dot-nesting we
     * key the list by ordinal position and carry the feature key on each
     * entry, then map back to a keyed map on save.
     *
     * Shape:
     *   `[ 0 => [ 'feature_key' => string, 'model' => string|null, 'instructions' => string|null ], ... ]`
     *
     * @since 1.0.0
     *
     * @var list<array{ feature_key: string, model: string|null, instructions: string|null }>
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

        // Intersect stored overrides with the currently-registered features
        // so we only show rows the admin can actually act on. Overrides for
        // features registered by packages that have since been removed stay
        // in the settings table but aren't hydrated into the form — this
        // prevents `save()` from resurrecting stale rows on the next write.
        $stored     = $featureSettings->all();
        $registered = $this->featuresList();

        $this->featureOverrides = [];

        foreach ( $registered as $feature ) {
            $key      = $feature['key'];
            $existing = $stored[ $key ] ?? [ 'model' => null, 'instructions' => null ];

            $this->featureOverrides[] = [
                'feature_key'  => $key,
                'model'        => $existing['model'] ?? null,
                'instructions' => $existing['instructions'] ?? null,
            ];
        }
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
            'featureOverrides.*.feature_key'  => [ 'required', 'string' ],
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
        // Consume the plaintext into a local and clear the public prop up
        // front so no non-happy-path (validate() throws, addError()+return,
        // partial save DB failure) can re-serialise the just-typed secret
        // into the next Livewire snapshot. Any subsequent addError() only
        // repaints the form with an empty api-key field.
        $typedApiKey  = $this->apiKey;
        $this->apiKey = null;

        $this->validate();

        $isOllama = 'ollama' === $this->provider;

        if ( $isOllama && ( null === $this->baseUrl || '' === trim( (string) $this->baseUrl ) ) ) {
            $this->addError( 'baseUrl', __( 'Ollama requires a base URL.' ) );

            return;
        }

        if ( ! $isOllama && ! $this->apiKeyPresent && ( null === $typedApiKey || '' === $typedApiKey ) ) {
            $this->addError( 'apiKey', __( 'An API key is required for :provider.', [ 'provider' => $this->provider ] ) );

            return;
        }

        // Preserve the existing key when the field is left blank — only
        // overwrite if the admin explicitly typed a new value.
        $apiKeyToStore = $typedApiKey;

        if ( null === $apiKeyToStore ) {
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

        // Only persist overrides for features that are currently registered
        // — the mount() intersection already limits the list, but a crafted
        // payload can inject arbitrary entries. Skip any row whose
        // feature_key isn't in the current registry.
        $registeredKeys = array_column( $this->featuresList(), 'key' );

        foreach ( $this->featureOverrides as $override ) {
            $featureKey = (string) ( $override['feature_key'] ?? '' );

            if ( '' === $featureKey || ! in_array( $featureKey, $registeredKeys, true ) ) {
                continue;
            }

            $featureSettings->setModel( $featureKey, $this->normaliseNullable( $override['model'] ?? null ) );
            $featureSettings->setInstructions( $featureKey, $this->normaliseNullable( $override['instructions'] ?? null ) );
        }

        // Re-read the store to refresh the "•••••" indicator.
        $public              = $store->toPublicArray();
        $this->apiKeyPresent = (bool) $public['api_key_present'];

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
        // Same discipline as save(): move the plaintext out of the public
        // prop before anything else so the response snapshot never re-emits
        // the typed value.
        $typedApiKey  = $this->apiKey;
        $this->apiKey = null;

        if ( null === $typedApiKey ) {
            $existing    = $store->load();
            $typedApiKey = null === $existing ? '' : $existing->apiKey;
        }

        $credentials = new Credentials(
            provider: $this->provider,
            apiKey: (string) $typedApiKey,
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
