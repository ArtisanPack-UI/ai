<?php

declare( strict_types=1 );

use ArtisanPackUI\Ai\Credentials\Credentials;
use ArtisanPackUI\Ai\Support\LaravelAiAgentPrompter;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Files\Base64Image;
use Laravel\Ai\Files\LocalImage;
use Laravel\Ai\Files\RemoteImage;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\StructuredAnonymousAgent;

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

/**
 * Build a minimal {@see AgentResponse} carrying the given raw text so
 * {@see LaravelAiAgentPrompter::decodeOutput()} can be exercised end-to-end
 * without the laravel/ai provider machinery.
 */
function fake_agent_response( string $text ): AgentResponse
{
    return new AgentResponse( 'inv-test', $text, new Usage(), new Meta() );
}

/**
 * The output schema the analytics SegmentInsightAgent-style agents declare —
 * a required `patterns` array of objects. This is the shape that triggers the
 * Opus stringified-array quirk this coercion exists to repair.
 *
 * @return array<string, mixed>
 */
function patterns_output_schema(): array
{
    return [
        'type'       => 'object',
        'required'   => [ 'patterns' ],
        'properties' => [
            'patterns' => [
                'type'  => 'array',
                'items' => [ 'type' => 'object' ],
            ],
        ],
    ];
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

it( 'forwards resolved tools onto the structured agent it builds', function (): void {
    $prompter = new LaravelAiAgentPrompter();

    $agent = invoke_prompter( $prompter, 'buildAgent', 'Do the thing.', [], [ 'App\\Tools\\ReadPost' ] );

    expect( $agent )->toBeInstanceOf( StructuredAnonymousAgent::class );
    expect( $agent->tools )->toBe( [ 'App\\Tools\\ReadPost' ] );
    expect( $agent->instructions )->toBe( 'Do the thing.' );
} );

it( 'passes tools through the ap.ai.registerTools filter so a host registry can contribute', function (): void {
    $prompter = new LaravelAiAgentPrompter();

    addFilter( 'ap.ai.registerTools', function ( array $tools ): array {
        $tools[] = 'App\\Tools\\HostRegistered';

        return $tools;
    } );

    $resolved = invoke_prompter( $prompter, 'resolveTools', [ 'App\\Tools\\FromAgent' ], [] );

    expect( $resolved )->toBe( [ 'App\\Tools\\FromAgent', 'App\\Tools\\HostRegistered' ] );

    removeAllFilters( 'ap.ai.registerTools' );
} );

it( 'ignores a non-array return from the ap.ai.registerTools filter', function (): void {
    $prompter = new LaravelAiAgentPrompter();

    addFilter( 'ap.ai.registerTools', fn (): string => 'not-an-array' );

    $resolved = invoke_prompter( $prompter, 'resolveTools', [ 'App\\Tools\\FromAgent' ], [] );

    expect( $resolved )->toBe( [ 'App\\Tools\\FromAgent' ] );

    removeAllFilters( 'ap.ai.registerTools' );
} );

it( 'unwraps an Opus-stringified nested-object array payload back into a populated array', function (): void {
    $prompter = new LaravelAiAgentPrompter();

    // The exact shape Opus emits: the whole schema re-nested inside a JSON string.
    $response = fake_agent_response(
        '{"patterns":"{\"patterns\":[{\"observation\":\"spike\"},{\"observation\":\"dip\"}]}"}',
    );

    $output = invoke_prompter( $prompter, 'decodeOutput', $response, patterns_output_schema() );

    expect( $output['patterns'] )->toBeArray();
    expect( $output['patterns'] )->toHaveCount( 2 );
    expect( $output['patterns'][0]['observation'] )->toBe( 'spike' );
} );

it( 'unwraps a stringified bare-array payload back into a populated array', function (): void {
    $prompter = new LaravelAiAgentPrompter();

    $response = fake_agent_response(
        '{"patterns":"[{\"observation\":\"spike\"},{\"observation\":\"dip\"}]"}',
    );

    $output = invoke_prompter( $prompter, 'decodeOutput', $response, patterns_output_schema() );

    expect( $output['patterns'] )->toBeArray();
    expect( $output['patterns'] )->toHaveCount( 2 );
    expect( $output['patterns'][1]['observation'] )->toBe( 'dip' );
} );

it( 'leaves a proper array payload untouched (Sonnet path)', function (): void {
    $prompter = new LaravelAiAgentPrompter();

    $response = fake_agent_response(
        '{"patterns":[{"observation":"spike"}]}',
    );

    $output = invoke_prompter( $prompter, 'decodeOutput', $response, patterns_output_schema() );

    expect( $output['patterns'] )->toBe( [ [ 'observation' => 'spike' ] ] );
} );

it( 'does not coerce a string property that happens to hold JSON-looking text', function (): void {
    $prompter = new LaravelAiAgentPrompter();

    $schema = [
        'type'       => 'object',
        'properties' => [
            'summary' => [ 'type' => 'string' ],
        ],
    ];

    $response = fake_agent_response( '{"summary":"[1,2,3]"}' );

    $output = invoke_prompter( $prompter, 'decodeOutput', $response, $schema );

    // `summary` is declared a string, so the array-looking value is left alone.
    expect( $output['summary'] )->toBe( '[1,2,3]' );
} );

it( 'leaves a stringified value untouched when it matches neither coercion shape', function (): void {
    $prompter = new LaravelAiAgentPrompter();

    // A string that decodes to an object keyed differently — we cannot safely
    // guess which key holds the list, so the original value survives.
    $response = fake_agent_response( '{"patterns":"{\"other\":[1,2]}"}' );

    $output = invoke_prompter( $prompter, 'decodeOutput', $response, patterns_output_schema() );

    expect( $output['patterns'] )->toBe( '{"other":[1,2]}' );
} );

it( 'leaves an empty JSON object string untouched rather than coercing it to an array', function (): void {
    $prompter = new LaravelAiAgentPrompter();

    // `json_decode( '{}', true )` yields a list-shaped PHP array, but `{}` is a
    // JSON object, not a bare array — it must survive as the original string.
    $response = fake_agent_response( '{"patterns":"{}"}' );

    $output = invoke_prompter( $prompter, 'decodeOutput', $response, patterns_output_schema() );

    expect( $output['patterns'] )->toBe( '{}' );
} );

it( 'leaves a numeric-keyed JSON object string untouched even though it decodes list-shaped', function (): void {
    $prompter = new LaravelAiAgentPrompter();

    // `{"0":"value"}` decodes to `['value']`, which satisfies array_is_list(),
    // but it is a JSON object and must not be coerced.
    $response = fake_agent_response( '{"patterns":"{\"0\":\"value\"}"}' );

    $output = invoke_prompter( $prompter, 'decodeOutput', $response, patterns_output_schema() );

    expect( $output['patterns'] )->toBe( '{"0":"value"}' );
} );

it( 'leaves a stringified array field untouched when no schema is supplied', function (): void {
    $prompter = new LaravelAiAgentPrompter();

    $response = fake_agent_response( '{"patterns":"[1,2,3]"}' );

    $output = invoke_prompter( $prompter, 'decodeOutput', $response );

    expect( $output['patterns'] )->toBe( '[1,2,3]' );
} );
