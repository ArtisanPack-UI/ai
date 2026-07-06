<?php

/**
 * AI provider connection test JSON API controller.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ai\Http\Controllers\Api;

use ArtisanPackUI\Ai\Credentials\Credentials;
use ArtisanPackUI\Ai\Credentials\SettingsCredentialStore;
use ArtisanPackUI\Ai\Support\ConnectionTester;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Fire a lightweight provider probe against the credentials in the request
 * body, falling back to the stored key when the body omits `api_key`.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */
class TestConnectionController extends AbstractAdminController
{
    /**
     * POST /test-connection — probe the provider.
     *
     * @since 1.0.0
     *
     * @param  Request                  $request  Incoming HTTP request.
     * @param  ConnectionTester         $tester   Connection tester.
     * @param  SettingsCredentialStore  $store    Credential store.
     *
     * @return JsonResponse
     */
    public function __invoke( Request $request, ConnectionTester $tester, SettingsCredentialStore $store ): JsonResponse
    {
        $this->authorizeAdmin();

        $validated = $request->validate( [
            'provider'      => [ 'required', 'string', 'max:64' ],
            'api_key'       => [ 'nullable', 'string', 'max:512' ],
            'base_url'      => [ 'nullable', 'string', 'max:2048' ],
            'default_model' => [ 'nullable', 'string', 'max:255' ],
        ] );

        $typedKey = $validated['api_key'] ?? null;

        if ( null === $typedKey ) {
            $existing = $store->load();
            $typedKey = null === $existing ? '' : $existing->apiKey;
        }

        $credentials = new Credentials(
            provider: (string) $validated['provider'],
            apiKey: (string) $typedKey,
            defaultModel: $this->trimOrNull( $validated['default_model'] ?? null ),
            baseUrl: $this->trimOrNull( $validated['base_url'] ?? null ),
        );

        $result = $tester->test( $credentials );

        $status = ConnectionTester::RESULT_OK === $result['result'] ? 200 : 422;

        return new JsonResponse( $result, $status );
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
