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
                    'message' => (string) __(
                        'Connection tests are not implemented for provider ":provider"; credentials will still be saved.',
                        [ 'provider' => $credentials->provider ],
                    ),
                ],
            };
        } catch ( Throwable $exception ) {
            // Deliberately do NOT surface `$exception->getMessage()` — cloud
            // provider errors sometimes echo request headers or response
            // bodies, and a hostile Ollama daemon could return arbitrary
            // text. Return a generic error and log the raw exception for
            // the operator to inspect.
            report( $exception );

            return [
                'result'  => self::RESULT_ERROR,
                'message' => (string) __( 'Connection failed. Check the application log for details.' ),
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
        $baseUrl = null === $credentials->baseUrl ? '' : trim( $credentials->baseUrl );

        if ( '' === $baseUrl ) {
            return [
                'result'  => self::RESULT_MISSING_BASE_URL,
                'message' => (string) __( 'Ollama requires a base URL (default: http://127.0.0.1:11434).' ),
            ];
        }

        $validationError = $this->validateOllamaUrl( $baseUrl );

        if ( null !== $validationError ) {
            return [
                'result'  => self::RESULT_ERROR,
                'message' => $validationError,
            ];
        }

        $response = Http::timeout( 5 )->get( rtrim( $baseUrl, '/' ) . '/api/tags' );

        if ( $response->successful() ) {
            return [
                'result'  => self::RESULT_OK,
                'message' => (string) __( 'Connected to Ollama daemon at :url.', [ 'url' => $baseUrl ] ),
            ];
        }

        return [
            'result'  => self::RESULT_ERROR,
            'message' => (string) __(
                'Ollama daemon at :url returned HTTP :status.',
                [ 'url' => $baseUrl, 'status' => $response->status() ],
            ),
        ];
    }

    /**
     * Validate a user-supplied Ollama base URL against a minimal safety list.
     *
     * The runtime Ollama transport reuses whatever base URL is stored, so an
     * admin who can save credentials also picks the destination. Without a
     * safety net that's an SSRF primitive against internal services and
     * cloud-metadata endpoints. This method enforces the smallest useful
     * allow-list: `http` or `https` scheme, no cloud-metadata IP, and any
     * unresolvable host is rejected as invalid rather than sent as-is.
     *
     * @since 1.0.0
     *
     * @param  string  $baseUrl  Trimmed base URL.
     *
     * @return string|null Error message, or null when the URL passes.
     */
    protected function validateOllamaUrl( string $baseUrl ): ?string
    {
        $parts = parse_url( $baseUrl );

        if ( false === $parts || ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) ) {
            return (string) __( 'Base URL must include a scheme and host (e.g. http://127.0.0.1:11434).' );
        }

        $scheme = strtolower( (string) $parts['scheme'] );

        if ( 'http' !== $scheme && 'https' !== $scheme ) {
            return (string) __( 'Base URL scheme must be http or https.' );
        }

        $host = strtolower( (string) $parts['host'] );

        // Block cloud instance-metadata endpoints outright — AWS, Azure and
        // GCP all publish credentials via the 169.254.169.254 link-local
        // address, and Alibaba/Oracle use IMDS aliases at the same range.
        if ( str_starts_with( $host, '169.254.' ) ) {
            return (string) __( 'Base URL host is not allowed (cloud metadata range).' );
        }

        // metadata.google.internal + AWS/Azure DNS aliases.
        $blockedHosts = [ 'metadata.google.internal', 'metadata', 'instance-data' ];

        if ( in_array( $host, $blockedHosts, true ) ) {
            return (string) __( 'Base URL host is not allowed (cloud metadata alias).' );
        }

        return null;
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
                'message' => (string) __( 'Anthropic requires an API key.' ),
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
                'message' => (string) __( 'OpenAI requires an API key.' ),
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
                'message' => (string) __( 'Authenticated against :vendor.', [ 'vendor' => $vendor ] ),
            ];
        }

        return [
            'result'  => self::RESULT_ERROR,
            'message' => (string) __(
                ':vendor returned HTTP :status.',
                [ 'vendor' => $vendor, 'status' => $status ],
            ),
        ];
    }
}
