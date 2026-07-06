<?php

/**
 * Test-connection utility for admin UIs.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ai\Support;

use ArtisanPackUI\Ai\Credentials\Credentials;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Verifies whether stored credentials can actually reach the provider.
 *
 * The admin UI (issue #14) calls `test()` from a Livewire action to flash a
 * pass/fail toast. Kept small on purpose — a rich transport layer lives in
 * `laravel/ai`; this class only reaches for the cheapest liveness endpoint
 * available per provider so we don't burn tokens on every save.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */
final class ConnectionTester
{
    /**
     * Result codes returned by `test()`.
     */
    public const RESULT_OK               = 'ok';
    public const RESULT_UNSUPPORTED      = 'unsupported';
    public const RESULT_MISSING_KEY      = 'missing_key';
    public const RESULT_MISSING_BASE_URL = 'missing_base_url';
    public const RESULT_ERROR            = 'error';

    /**
     * Run a lightweight liveness check for the given credentials.
     *
     * Currently supports:
     *
     *   - `ollama`: `GET {base_url}/api/tags` — always safe, no cost.
     *   - `anthropic`: `GET https://api.anthropic.com/v1/models` with API key
     *     — quick + free, verifies the key.
     *   - `openai`: `GET https://api.openai.com/v1/models` with the API key.
     *
     * Every other provider returns `RESULT_UNSUPPORTED` for now — the admin
     * UI surfaces that as "connection check unavailable" without failing
     * the save.
     *
     * @since 1.0.0
     *
     * @param  Credentials  $credentials  Credentials to probe.
     *
     * @return array{ result: string, message: string }
     */
    public function test( Credentials $credentials ): array
    {
        try {
            return match ( $credentials->provider ) {
                'ollama'    => $this->testOllama( $credentials ),
                'anthropic' => $this->testAnthropic( $credentials ),
                'openai'    => $this->testOpenai( $credentials ),
                default     => [
                    'result'  => self::RESULT_UNSUPPORTED,
                    'message' => sprintf(
                        'Connection tests are not implemented for provider "%s"; credentials will still be saved.',
                        $credentials->provider,
                    ),
                ],
            };
        } catch ( Throwable $exception ) {
            return [
                'result'  => self::RESULT_ERROR,
                'message' => $exception->getMessage(),
            ];
        }
    }

    /**
     * Probe an Ollama daemon at `{base_url}/api/tags`.
     *
     * @since 1.0.0
     *
     * @param  Credentials  $credentials  Ollama credentials.
     *
     * @return array{ result: string, message: string }
     */
    protected function testOllama( Credentials $credentials ): array
    {
        $baseUrl = $credentials->baseUrl;

        if ( null === $baseUrl || '' === $baseUrl ) {
            return [
                'result'  => self::RESULT_MISSING_BASE_URL,
                'message' => 'Ollama requires a base URL (default: http://127.0.0.1:11434).',
            ];
        }

        $response = Http::timeout( 5 )->get( rtrim( $baseUrl, '/' ) . '/api/tags' );

        if ( $response->successful() ) {
            return [
                'result'  => self::RESULT_OK,
                'message' => 'Connected to Ollama daemon at ' . $baseUrl . '.',
            ];
        }

        return [
            'result'  => self::RESULT_ERROR,
            'message' => sprintf(
                'Ollama daemon at %s returned HTTP %d.',
                $baseUrl,
                $response->status(),
            ),
        ];
    }

    /**
     * Probe Anthropic's `/v1/models` endpoint.
     *
     * @since 1.0.0
     *
     * @param  Credentials  $credentials  Anthropic credentials.
     *
     * @return array{ result: string, message: string }
     */
    protected function testAnthropic( Credentials $credentials ): array
    {
        if ( '' === $credentials->apiKey ) {
            return [
                'result'  => self::RESULT_MISSING_KEY,
                'message' => 'Anthropic requires an API key.',
            ];
        }

        $response = Http::timeout( 5 )
            ->withHeaders( [
                'x-api-key'         => $credentials->apiKey,
                'anthropic-version' => '2023-06-01',
            ] )
            ->get( 'https://api.anthropic.com/v1/models' );

        return $this->summariseCloudResponse( $response->successful(), $response->status(), 'Anthropic' );
    }

    /**
     * Probe OpenAI's `/v1/models` endpoint.
     *
     * @since 1.0.0
     *
     * @param  Credentials  $credentials  OpenAI credentials.
     *
     * @return array{ result: string, message: string }
     */
    protected function testOpenai( Credentials $credentials ): array
    {
        if ( '' === $credentials->apiKey ) {
            return [
                'result'  => self::RESULT_MISSING_KEY,
                'message' => 'OpenAI requires an API key.',
            ];
        }

        $response = Http::timeout( 5 )
            ->withToken( $credentials->apiKey )
            ->get( 'https://api.openai.com/v1/models' );

        return $this->summariseCloudResponse( $response->successful(), $response->status(), 'OpenAI' );
    }

    /**
     * Shared success/failure summariser for cloud providers.
     *
     * @since 1.0.0
     *
     * @param  bool    $successful  Whether the response was 2xx.
     * @param  int     $status      HTTP status code.
     * @param  string  $vendor      Human-readable vendor name.
     *
     * @return array{ result: string, message: string }
     */
    protected function summariseCloudResponse( bool $successful, int $status, string $vendor ): array
    {
        if ( $successful ) {
            return [
                'result'  => self::RESULT_OK,
                'message' => sprintf( 'Authenticated against %s.', $vendor ),
            ];
        }

        return [
            'result'  => self::RESULT_ERROR,
            'message' => sprintf( '%s returned HTTP %d.', $vendor, $status ),
        ];
    }
}
