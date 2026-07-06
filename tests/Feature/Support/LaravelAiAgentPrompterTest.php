<?php

declare( strict_types=1 );

use ArtisanPackUI\Ai\Credentials\Credentials;
use ArtisanPackUI\Ai\Support\LaravelAiAgentPrompter;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Files\Base64Image;
use Laravel\Ai\Files\LocalImage;
use Laravel\Ai\Files\RemoteImage;

/**
 * Isolated tests for the seams inside {@see LaravelAiAgentPrompter} — the
 * regressions surfaced during dev-app manual testing (provider config
 * shape, JsonSchema conversion, File attachment mapping, runtime provider
 * registration) all live in this file so a future refactor can't silently
 * re-break the laravel/ai adapter path.
 *
 * The `prompt()` entry point itself is not exercised end-to-end here — it
 * calls into laravel/ai's provider machinery, which needs `Ai::fake()`
 * plumbing beyond the scope of these unit checks. The three shipped
 * agents' feature tests cover the outer flow via a FakeAgentPrompter.
 */

/**
 * Reflection helper — exposes a protected method for testing without
 * subclassing (subclassing would tie the test to a specific override, and
 * these behaviours are meant to be the default the shipped class provides).
 */
function invoke_prompter( LaravelAiAgentPrompter $prompter, string $method, mixed ...$args ): mixed
{
    $reflect = new ReflectionMethod( $prompter, $method );
    $reflect->setAccessible( true );

    return $reflect->invoke( $prompter, ...$args );
}

it( 'splits a structured message into a text prompt and typed attachments', function (): void {
    $prompter = new LaravelAiAgentPrompter();

    [ $prompt, $attachments ] = invoke_prompter( $prompter, 'normalizeMessage', [
        [ 'type' => 'text', 'text' => 'Describe this image.' ],
        [ 'type' => 'image', 'source' => 'url', 'value' => 'https://example.com/x.jpg' ],
        [ 'type' => 'text', 'text' => 'Focus on foreground.' ],
    ] );

    expect( $prompt )->toBe( "Describe this image.\n\nFocus on foreground." );
    expect( $attachments )->toHaveCount( 1 );
    expect( $attachments[0] )->toBeInstanceOf( RemoteImage::class );
} );

it( 'passes string messages straight through with no attachments', function (): void {
    $prompter = new LaravelAiAgentPrompter();

    [ $prompt, $attachments ] = invoke_prompter( $prompter, 'normalizeMessage', 'hello world' );

    expect( $prompt )->toBe( 'hello world' );
    expect( $attachments )->toBe( [] );
} );

it( 'maps each image source to the matching laravel/ai File subclass', function (): void {
    $prompter = new LaravelAiAgentPrompter();

    $url = invoke_prompter( $prompter, 'toFileAttachment', [
        'type' => 'image', 'source' => 'url', 'value' => 'https://example.com/x.jpg',
    ] );
    $path = invoke_prompter( $prompter, 'toFileAttachment', [
        'type' => 'image', 'source' => 'path', 'value' => '/tmp/x.jpg',
    ] );
    $base64 = invoke_prompter( $prompter, 'toFileAttachment', [
        'type' => 'image', 'source' => 'base64', 'value' => 'iVBORw0KGgo=',
    ] );

    expect( $url )->toBeInstanceOf( RemoteImage::class );
    expect( $path )->toBeInstanceOf( LocalImage::class );
    expect( $base64 )->toBeInstanceOf( Base64Image::class );
} );

it( 'strips a data URI prefix off base64 payloads and leaves bare base64 alone', function (): void {
    $prompter = new LaravelAiAgentPrompter();

    $stripped    = invoke_prompter( $prompter, 'stripBase64Prefix', 'data:image/png;base64,iVBORw0KGgo=' );
    $passthrough = invoke_prompter( $prompter, 'stripBase64Prefix', 'iVBORw0KGgo=' );

    expect( $stripped )->toBe( 'iVBORw0KGgo=' );
    expect( $passthrough )->toBe( 'iVBORw0KGgo=' );
} );

it( 'drops unknown attachment parts instead of throwing', function (): void {
    $prompter = new LaravelAiAgentPrompter();

    expect( invoke_prompter( $prompter, 'toFileAttachment', [
        'type' => 'unknown', 'source' => 'url', 'value' => 'x',
    ] ) )->toBeNull();

    expect( invoke_prompter( $prompter, 'toFileAttachment', [
        'type' => 'image', 'source' => 'weird', 'value' => 'x',
    ] ) )->toBeNull();

    expect( invoke_prompter( $prompter, 'toFileAttachment', [
        'type' => 'image',
    ] ) )->toBeNull();
} );

it( 'converts a raw JSON-Schema array into laravel/ai Type instances keyed by property name', function (): void {
    $prompter = new LaravelAiAgentPrompter();

    $result = invoke_prompter( $prompter, 'buildLaravelJsonSchema', [
        'type'       => 'object',
        'required'   => [ 'alt_text', 'confidence' ],
        'properties' => [
            'alt_text'   => [ 'type' => 'string', 'maxLength' => 150 ],
            'confidence' => [ 'type' => 'number', 'minimum' => 0, 'maximum' => 1 ],
            'warnings'   => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
        ],
    ] );

    expect( $result )->toHaveKeys( [ 'alt_text', 'confidence', 'warnings' ] );

    foreach ( $result as $type ) {
        expect( $type )->toBeInstanceOf( Type::class );
    }
} );

it( 'returns an empty properties map when the schema has no properties', function (): void {
    $prompter = new LaravelAiAgentPrompter();

    $result = invoke_prompter( $prompter, 'buildLaravelJsonSchema', [ 'type' => 'object' ] );

    expect( $result )->toBe( [] );
} );

it( 'registers a runtime provider under a unique underscored key', function (): void {
    $prompter = new LaravelAiAgentPrompter();

    $credentials = new Credentials(
        provider: 'anthropic',
        apiKey: 'sk-example',
        defaultModel: 'claude-haiku-4-5',
        baseUrl: 'https://api.anthropic.com/v1',
    );

    $name = invoke_prompter( $prompter, 'registerRuntimeProvider', $credentials );

    expect( $name )->toStartWith( 'artisanpack_ai_runtime_anthropic_' );
    expect( $name )->not->toContain( '.' );

    $registered = config( 'ai.providers.' . $name );

    expect( $registered )->toMatchArray( [
        'driver' => 'anthropic',
        'key'    => 'sk-example',
        'url'    => 'https://api.anthropic.com/v1',
    ] );
} );

it( 'mints a fresh runtime provider name per call so concurrent calls do not clobber each other', function (): void {
    $prompter = new LaravelAiAgentPrompter();

    $credentials = new Credentials(
        provider: 'anthropic',
        apiKey: 'sk-a',
        defaultModel: 'claude-haiku-4-5',
    );

    $nameA = invoke_prompter( $prompter, 'registerRuntimeProvider', $credentials );

    $nameB = invoke_prompter( $prompter, 'registerRuntimeProvider', new Credentials(
        provider: 'anthropic',
        apiKey: 'sk-b',
        defaultModel: 'claude-haiku-4-5',
    ) );

    expect( $nameA )->not->toBe( $nameB );

    // Both configs survive independently.
    expect( config( 'ai.providers.' . $nameA . '.key' ) )->toBe( 'sk-a' );
    expect( config( 'ai.providers.' . $nameB . '.key' ) )->toBe( 'sk-b' );
} );

it( 'omits blank credential fields from the registered provider config', function (): void {
    $prompter = new LaravelAiAgentPrompter();

    $name = invoke_prompter( $prompter, 'registerRuntimeProvider', new Credentials(
        provider: 'ollama',
        apiKey: '',
        defaultModel: 'llama3.2:3b',
        baseUrl: null,
    ) );

    $registered = config( 'ai.providers.' . $name );

    expect( $registered )->toBe( [ 'driver' => 'ollama' ] );
} );

it( 'releases the runtime provider config back off the Config repository', function (): void {
    $prompter = new LaravelAiAgentPrompter();

    $name = invoke_prompter( $prompter, 'registerRuntimeProvider', new Credentials(
        provider: 'anthropic',
        apiKey: 'sk-leak-test',
        defaultModel: 'claude-haiku-4-5',
    ) );

    expect( config( 'ai.providers.' . $name ) )->not->toBeNull();

    invoke_prompter( $prompter, 'releaseRuntimeProvider', $name );

    // The provider entry itself is gone. Under Octane / queue this
    // prevents a slow leak of API-key strings into the Config array.
    expect( config( 'ai.providers.' . $name ) )->toBeNull();
} );

it( 'strips a fenced JSON payload before decoding so a chatty model does not fail the run', function (): void {
    $prompter = new LaravelAiAgentPrompter();

    $stripped = invoke_prompter( $prompter, 'stripCodeFence', "```json\n{\"alt_text\":\"cat\"}\n```" );
    $withLang = invoke_prompter( $prompter, 'stripCodeFence', "```\n{\"x\":1}\n```" );
    $bare     = invoke_prompter( $prompter, 'stripCodeFence', '{"x":1}' );

    expect( $stripped )->toBe( '{"alt_text":"cat"}' );
    expect( $withLang )->toBe( '{"x":1}' );
    expect( $bare )->toBe( '{"x":1}' );
} );

it( 'sanitises a hostile provider driver so the config key stays a safe identifier', function (): void {
    $prompter = new LaravelAiAgentPrompter();

    $name = invoke_prompter( $prompter, 'registerRuntimeProvider', new Credentials(
        provider: 'evil.driver/here',
        apiKey: 'x',
        defaultModel: 'x',
    ) );

    expect( $name )->not->toContain( '.' );
    expect( $name )->not->toContain( '/' );
    expect( $name )->toStartWith( 'artisanpack_ai_runtime_evil_driver_here_' );
} );
