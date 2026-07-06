<?php

/**
 * AI settings JSON API controller.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ai\Http\Controllers\Api;

use ArtisanPackUI\Ai\Contracts\FeatureRegistry;
use ArtisanPackUI\Ai\Credentials\Credentials;
use ArtisanPackUI\Ai\Credentials\SettingsCredentialStore;
use ArtisanPackUI\Ai\Registry\FeatureDefinition;
use ArtisanPackUI\Ai\Support\FeatureSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read and update the shared credential + per-feature override settings.
 *
 * The plaintext API key is never returned. Callers receive only whether a
 * key is stored (`api_key_present`) — mirroring the Livewire admin's
 * write-only behaviour.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */
class SettingsController extends AbstractAdminController
{
    /**
     * GET /settings — return the safe public settings payload.
     *
     * @since 1.0.0
     *
     * @param  SettingsCredentialStore  $store            Credential store.
     * @param  FeatureSettings          $featureSettings  Per-feature override store.
     * @param  FeatureRegistry          $registry         Feature registry.
     *
     * @return JsonResponse
     */
    public function show(
        SettingsCredentialStore $store,
        FeatureSettings $featureSettings,
        FeatureRegistry $registry,
    ): JsonResponse {
        $this->authorizeAdmin();

        $credentials = $store->toPublicArray();
        $stored      = $featureSettings->all();

        $overrides = [];

        /** @var FeatureDefinition $definition */
        foreach ( $registry->all() as $definition ) {
            $existing = $stored[ $definition->featureKey ] ?? [ 'model' => null, 'instructions' => null ];

            $overrides[] = [
                'feature_key'  => $definition->featureKey,
                'package'      => $definition->package,
                'model'        => $existing['model'] ?? null,
                'instructions' => $existing['instructions'] ?? null,
            ];
        }

        return new JsonResponse( [
            'credentials'       => $credentials,
            'feature_overrides' => $overrides,
        ] );
    }

    /**
     * PUT /settings — persist credential + per-feature overrides.
     *
     * @since 1.0.0
     *
     * @param  Request                  $request          Incoming HTTP request.
     * @param  SettingsCredentialStore  $store            Credential store.
     * @param  FeatureSettings          $featureSettings  Per-feature override store.
     * @param  FeatureRegistry          $registry         Feature registry.
     *
     * @return JsonResponse
     */
    public function update(
        Request $request,
        SettingsCredentialStore $store,
        FeatureSettings $featureSettings,
        FeatureRegistry $registry,
    ): JsonResponse {
        $this->authorizeAdmin();

        $validated = $request->validate( [
            'provider'                         => [ 'required', 'string', 'max:64' ],
            'api_key'                          => [ 'nullable', 'string', 'max:512' ],
            'base_url'                         => [ 'nullable', 'string', 'max:2048' ],
            'default_model'                    => [ 'nullable', 'string', 'max:255' ],
            'feature_overrides'                => [ 'nullable', 'array' ],
            'feature_overrides.*.feature_key'  => [ 'required', 'string', 'max:255' ],
            'feature_overrides.*.model'        => [ 'nullable', 'string', 'max:255' ],
            'feature_overrides.*.instructions' => [ 'nullable', 'string', 'max:20000' ],
        ] );

        $provider = (string) $validated['provider'];
        $isOllama = 'ollama' === $provider;
        $typedKey = $validated['api_key'] ?? null;
        $baseUrl  = $this->trimOrNull( $validated['base_url'] ?? null );

        if ( $isOllama && ( null === $baseUrl || '' === $baseUrl ) ) {
            return new JsonResponse( [
                'message' => __( 'Validation failed.' ),
                'errors'  => [ 'base_url' => [ (string) __( 'Ollama requires a base URL.' ) ] ],
            ], 422 );
        }

        $existing = $store->load();

        if ( ! $isOllama && null === $typedKey && null === $existing ) {
            return new JsonResponse( [
                'message' => __( 'Validation failed.' ),
                'errors'  => [ 'api_key' => [ (string) __( 'An API key is required.' ) ] ],
            ], 422 );
        }

        // Reject a silent provider switch that would re-bind the previously
        // stored key to a different vendor — the stored ciphertext is only
        // valid for the provider it was minted for, so reusing it against a
        // new provider produces 401s on the first real call.
        if (
            ! $isOllama
            && null === $typedKey
            && null !== $existing
            && $existing->provider !== $provider
        ) {
            return new JsonResponse( [
                'message' => __( 'Validation failed.' ),
                'errors'  => [ 'api_key' => [ (string) __( 'Provide a new API key when switching providers.' ) ] ],
            ], 422 );
        }

        $apiKeyToStore = $typedKey ?? ( null === $existing ? '' : $existing->apiKey );

        $store->save( new Credentials(
            provider: $provider,
            apiKey: (string) $apiKeyToStore,
            defaultModel: $this->trimOrNull( $validated['default_model'] ?? null ),
            baseUrl: $baseUrl,
        ) );

        $registeredKeys = $registry->all()
            ->map( fn ( FeatureDefinition $definition ): string => $definition->featureKey )
            ->all();

        foreach ( (array) ( $validated['feature_overrides'] ?? [] ) as $override ) {
            $key = (string) ( $override['feature_key'] ?? '' );

            if ( '' === $key || ! in_array( $key, $registeredKeys, true ) ) {
                continue;
            }

            $featureSettings->setModel( $key, $this->trimOrNull( $override['model'] ?? null ) );
            $featureSettings->setInstructions( $key, $this->trimOrNull( $override['instructions'] ?? null ) );
        }

        return $this->show( $store, $featureSettings, $registry );
    }

    /**
     * Coerce blank strings to null.
     *
     * @since 1.0.0
     *
     * @param  string|null  $value  Raw value.
     *
     * @return string|null
     */
    protected function trimOrNull( ?string $value ): ?string
    {
        if ( null === $value ) {
            return null;
        }

        $trimmed = trim( $value );

        return '' === $trimmed ? null : $trimmed;
    }
}
