<?php

/**
 * End-to-end Ollama connectivity check.
 *
 * Skipped unless a local (or CI-side) Ollama daemon is available and
 * `ARTISANPACK_AI_OLLAMA_E2E=1` is set. See README "Local models" for
 * setup. Kept as a real HTTP round-trip on purpose — mocking it away
 * would defeat the point of validating our Ollama-first-class stance.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

use ArtisanPackUI\Ai\Credentials\Credentials;
use ArtisanPackUI\Ai\Support\ConnectionTester;

beforeEach( function (): void {
    if ( '1' !== env( 'ARTISANPACK_AI_OLLAMA_E2E' ) ) {
        $this->markTestSkipped( 'ARTISANPACK_AI_OLLAMA_E2E is not set; skipping real Ollama round-trip.' );
    }
} )->group( 'ollama-e2e' );

it( 'reaches the local Ollama daemon and lists at least one model', function (): void {
    $baseUrl = env( 'ARTISANPACK_AI_BASE_URL', 'http://127.0.0.1:11434' );

    $tester = app( ConnectionTester::class );

    $result = $tester->test( new Credentials(
        provider: 'ollama',
        apiKey: '',
        baseUrl: $baseUrl,
    ) );

    expect( $result['result'] )->toBe( ConnectionTester::RESULT_OK );
} )->group( 'ollama-e2e' );
