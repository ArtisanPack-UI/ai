<?php

declare( strict_types=1 );

use ArtisanPackUI\Ai\Credentials\Credentials;
use ArtisanPackUI\Ai\Support\LaravelAiAgentPrompter;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\StructuredAnonymousAgent;

/**
 * Hooks fired by {@see LaravelAiAgentPrompter}: `ap.ai.promptGenerated`
 * (filter, before the provider call) and `ap.ai.responseReceived` (action,
 * after). Both are the shared audit / safety-prompt seam every ArtisanPack
 * UI agent flows through — a downstream package can hang PII scrubbing or
 * uniform logging off these without patching each concrete agent.
 *
 * The real provider call is stubbed via a testing subclass so these tests
 * don't require `Ai::fake()` plumbing.
 */

/**
 * Prompter subclass that short-circuits the actual laravel/ai call and
 * captures the prompt string that would have been sent. Lets tests assert
 * both that the filter mutated the prompt AND that the mutated value is
 * what reaches the provider.
 */
class RecordingPrompter extends LaravelAiAgentPrompter
{
    public ?string $sentPrompt = null;

    public string $stubResponseText = '{"ok":true}';

    protected function sendToProvider(
        StructuredAnonymousAgent $agent,
        string $prompt,
        array $attachments,
        string $providerName,
        string $model,
    ): AgentResponse {
        $this->sentPrompt = $prompt;

        return new AgentResponse(
            invocationId: 'test-invocation',
            text: $this->stubResponseText,
            usage: new Usage( promptTokens: 12, completionTokens: 7 ),
            meta: new Meta( provider: 'test', model: $model ),
        );
    }
}

function credentialsFixture(): Credentials
{
    return new Credentials(
        provider: 'anthropic',
        apiKey: 'sk-hooks-test',
        defaultModel: 'claude-haiku-4-5',
    );
}

afterEach( function (): void {
    removeAllFilters( 'ap.ai.promptGenerated' );
    removeAllActions( 'ap.ai.responseReceived' );
} );

it( 'runs the prompt through the ap.ai.promptGenerated filter before sending', function (): void {
    addFilter(
        'ap.ai.promptGenerated',
        fn ( string $prompt, array $context ): string => '[SAFETY] ' . $prompt,
    );

    $prompter = new RecordingPrompter();

    $prompter->prompt(
        credentials: credentialsFixture(),
        model: 'claude-haiku-4-5',
        instructions: 'be brief',
        message: 'Summarize this.',
        outputSchema: [ 'type' => 'object', 'properties' => [ 'ok' => [ 'type' => 'boolean' ] ] ],
    );

    expect( $prompter->sentPrompt )->toBe( '[SAFETY] Summarize this.' );
} );

it( 'passes provider, model, instructions, and attachment count in the filter context', function (): void {
    $captured = null;

    addFilter(
        'ap.ai.promptGenerated',
        function ( string $prompt, array $context ) use ( &$captured ): string {
            $captured = $context;

            return $prompt;
        },
    );

    $prompter = new RecordingPrompter();

    $prompter->prompt(
        credentials: credentialsFixture(),
        model: 'claude-haiku-4-5',
        instructions: 'be brief',
        message: [
            [ 'type' => 'text', 'text' => 'Describe this image.' ],
            [ 'type' => 'image', 'source' => 'url', 'value' => 'https://example.com/a.jpg' ],
            [ 'type' => 'image', 'source' => 'url', 'value' => 'https://example.com/b.jpg' ],
        ],
        outputSchema: [ 'type' => 'object' ],
    );

    expect( $captured )->toBe( [
        'provider'     => 'anthropic',
        'model'        => 'claude-haiku-4-5',
        'instructions' => 'be brief',
        'attachments'  => 2,
    ] );
} );

it( 'fires ap.ai.responseReceived with the raw provider text after the call', function (): void {
    $received = null;
    $context  = null;

    addAction(
        'ap.ai.responseReceived',
        function ( string $response, array $ctx ) use ( &$received, &$context ): void {
            $received = $response;
            $context  = $ctx;
        },
    );

    $prompter                   = new RecordingPrompter();
    $prompter->stubResponseText = '{"summary":"done"}';

    $prompter->prompt(
        credentials: credentialsFixture(),
        model: 'claude-haiku-4-5',
        instructions: 'be brief',
        message: 'Go.',
        outputSchema: [ 'type' => 'object', 'properties' => [ 'summary' => [ 'type' => 'string' ] ] ],
    );

    expect( $received )->toBe( '{"summary":"done"}' );
    expect( $context )->toMatchArray( [
        'provider' => 'anthropic',
        'model'    => 'claude-haiku-4-5',
    ] );
} );

it( 'fires responseReceived with the raw text even when downstream decoding will fail', function (): void {
    // Listeners doing audit logging need to see EVERY provider response,
    // including the ones we're about to reject as unparseable — otherwise
    // the exact payloads that matter for debugging disappear from logs.
    $received = null;

    addAction(
        'ap.ai.responseReceived',
        function ( string $response ) use ( &$received ): void {
            $received = $response;
        },
    );

    $prompter                   = new RecordingPrompter();
    $prompter->stubResponseText = 'not-json-at-all';

    try {
        $prompter->prompt(
            credentials: credentialsFixture(),
            model: 'claude-haiku-4-5',
            instructions: 'be brief',
            message: 'Go.',
            outputSchema: [ 'type' => 'object' ],
        );
    } catch ( Throwable ) {
        // Decode will throw a FeatureError. We only care that the action fired.
    }

    expect( $received )->toBe( 'not-json-at-all' );
} );
