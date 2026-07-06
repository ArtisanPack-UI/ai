<?php

declare( strict_types=1 );

use ArtisanPackUI\Ai\Credentials\Credentials;
use ArtisanPackUI\Ai\Support\ConnectionTester;
use Illuminate\Support\Facades\Http;

it( 'reports OK when Ollama /api/tags responds successfully', function (): void {
    Http::fake( [
        'http://127.0.0.1:11434/api/tags' => Http::response( [
            'models' => [ [ 'name' => 'llama3.2:3b' ] ],
        ], 200 ),
    ] );

    $tester = app( ConnectionTester::class );

    $result = $tester->test( new Credentials(
        provider: 'ollama',
        apiKey: '',
        defaultModel: 'llama3.2:3b',
        baseUrl: 'http://127.0.0.1:11434',
    ) );

    expect( $result['result'] )->toBe( ConnectionTester::RESULT_OK );
    expect( $result['message'] )->toContain( 'Connected to Ollama daemon' );
} );

it( 'reports missing base URL when Ollama is configured without one', function (): void {
    Http::fake();

    $tester = app( ConnectionTester::class );

    $result = $tester->test( new Credentials(
        provider: 'ollama',
        apiKey: '',
    ) );

    expect( $result['result'] )->toBe( ConnectionTester::RESULT_MISSING_BASE_URL );
    Http::assertNothingSent();
} );

it( 'reports error when Ollama returns a non-2xx status', function (): void {
    Http::fake( [
        'http://127.0.0.1:11434/api/tags' => Http::response( '', 500 ),
    ] );

    $tester = app( ConnectionTester::class );

    $result = $tester->test( new Credentials(
        provider: 'ollama',
        apiKey: '',
        baseUrl: 'http://127.0.0.1:11434',
    ) );

    expect( $result['result'] )->toBe( ConnectionTester::RESULT_ERROR );
    expect( $result['message'] )->toContain( 'HTTP 500' );
} );

it( 'catches transport errors and returns RESULT_ERROR', function (): void {
    Http::fake( function (): void {
        throw new RuntimeException( 'Connection refused' );
    } );

    $tester = app( ConnectionTester::class );

    $result = $tester->test( new Credentials(
        provider: 'ollama',
        apiKey: '',
        baseUrl: 'http://127.0.0.1:11434',
    ) );

    expect( $result['result'] )->toBe( ConnectionTester::RESULT_ERROR );
    expect( $result['message'] )->toBe( 'Connection refused' );
} );

it( 'reports OK for Anthropic when /v1/models returns 200', function (): void {
    Http::fake( [
        'https://api.anthropic.com/v1/models' => Http::response( [ 'data' => [] ], 200 ),
    ] );

    $tester = app( ConnectionTester::class );

    $result = $tester->test( new Credentials(
        provider: 'anthropic',
        apiKey: 'sk-live-test',
    ) );

    expect( $result['result'] )->toBe( ConnectionTester::RESULT_OK );
    expect( $result['message'] )->toContain( 'Anthropic' );
} );

it( 'reports missing key when Anthropic credentials lack an API key', function (): void {
    Http::fake();

    $tester = app( ConnectionTester::class );

    $result = $tester->test( new Credentials( provider: 'anthropic', apiKey: '' ) );

    expect( $result['result'] )->toBe( ConnectionTester::RESULT_MISSING_KEY );
    Http::assertNothingSent();
} );

it( 'returns UNSUPPORTED for providers without a probe implemented', function (): void {
    Http::fake();

    $tester = app( ConnectionTester::class );

    $result = $tester->test( new Credentials(
        provider: 'cohere',
        apiKey: 'anything',
    ) );

    expect( $result['result'] )->toBe( ConnectionTester::RESULT_UNSUPPORTED );
    expect( $result['message'] )->toContain( 'cohere' );
    Http::assertNothingSent();
} );
