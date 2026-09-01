<?php

declare( strict_types=1 );

use ArtisanPackUI\Ai\Agents\ContentRewriteAgent;
use ArtisanPackUI\Ai\Agents\SummarizationAgent;
use ArtisanPackUI\Ai\Contracts\AgentPrompter;
use ArtisanPackUI\Ai\Events\AgentUsageRecorded;
use ArtisanPackUI\Ai\Facades\Ai;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Support\FakeAgentPrompter;

it( 'is not faking until Ai::fake() is called', function (): void {
    expect( Ai::isFaking() )->toBeFalse();
    expect( Ai::getFake() )->toBeNull();

    Ai::fake();

    expect( Ai::isFaking() )->toBeTrue();
    expect( Ai::getFake() )->not->toBeNull();
} );

it( 'returns the seeded default response for an agent class', function (): void {
    Ai::fake( [
        SummarizationAgent::class => [
            'summary'    => 'Deterministic summary.',
            'key_points' => [ 'point a' ],
            'caveats'    => [],
        ],
    ] );

    $result = SummarizationAgent::for( [ 'items' => [ 'x' ] ] )->run();

    expect( $result )->toBe( [
        'summary'    => 'Deterministic summary.',
        'key_points' => [ 'point a' ],
        'caveats'    => [],
    ] );
} );

it( 'drains queued responses FIFO, then falls back to the default', function (): void {
    $fake = Ai::fake( [
        SummarizationAgent::class => [ 'summary' => 'default' ],
    ] );

    $fake->queue( SummarizationAgent::class, [ 'summary' => 'first' ] );
    $fake->queue( SummarizationAgent::class, [ 'summary' => 'second' ] );

    expect( SummarizationAgent::for( [] )->run() )->toBe( [ 'summary' => 'first' ] );
    expect( SummarizationAgent::for( [] )->run() )->toBe( [ 'summary' => 'second' ] );
    expect( SummarizationAgent::for( [] )->run() )->toBe( [ 'summary' => 'default' ] );
} );

it( 'keys responses per agent class', function (): void {
    Ai::fake( [
        SummarizationAgent::class  => [ 'summary' => 'from summarizer' ],
        ContentRewriteAgent::class => [ 'text' => 'from rewriter' ],
    ] );

    expect( SummarizationAgent::for( [] )->run() )->toBe( [ 'summary' => 'from summarizer' ] );
    expect( ContentRewriteAgent::for( [] )->run() )->toBe( [ 'text' => 'from rewriter' ] );
} );

it( 'throws a helpful error when no response is queued for the agent', function (): void {
    Ai::fake();

    expect( fn () => SummarizationAgent::for( [] )->run() )
        ->toThrow( LogicException::class, 'No fake AI response is queued for agent' );
} );

it( 'short-circuits the whole pipeline — no credentials, prompter, or usage event', function (): void {
    Event::fake( [ AgentUsageRecorded::class ] );

    $prompter = new FakeAgentPrompter();
    $this->app->instance( AgentPrompter::class, $prompter );

    Ai::fake( [
        SummarizationAgent::class => [ 'summary' => 'faked' ],
    ] );

    // Input that would normally throw a FeatureError in normalizeInput(),
    // proving the fake bypasses execute() entirely.
    $result = SummarizationAgent::for( 'not-an-array' )->run();

    expect( $result )->toBe( [ 'summary' => 'faked' ] );
    expect( $prompter->calls )->toBeEmpty();
    Event::assertNotDispatched( AgentUsageRecorded::class );
} );

it( 'records the input each agent was run with', function (): void {
    $fake = Ai::fake( [
        SummarizationAgent::class => [ 'summary' => 'ok' ],
    ] );

    SummarizationAgent::for( [ 'items' => [ 'alpha' ] ] )->run();
    SummarizationAgent::for( [ 'items' => [ 'beta' ] ] )->run();

    $runs = $fake->ran( SummarizationAgent::class );

    expect( $runs )->toHaveCount( 2 );
    expect( $runs[0]['input'] )->toBe( [ 'items' => [ 'alpha' ] ] );
    expect( $runs[1]['input'] )->toBe( [ 'items' => [ 'beta' ] ] );
    expect( $runs[0]['feature'] )->toBe( 'ai.summarize' );
} );

it( 'asserts which agent ran and how many times', function (): void {
    $fake = Ai::fake( [
        SummarizationAgent::class => [ 'summary' => 'ok' ],
    ] );

    SummarizationAgent::for( [] )->run();
    SummarizationAgent::for( [] )->run();

    $fake->assertRan( SummarizationAgent::class )
        ->assertRanTimes( SummarizationAgent::class, 2 )
        ->assertNotRan( ContentRewriteAgent::class );
} );

it( 'asserts a run matched a callback on its input', function (): void {
    $fake = Ai::fake( [
        SummarizationAgent::class => [ 'summary' => 'ok' ],
    ] );

    SummarizationAgent::for( [ 'items' => [ 'needle' ] ] )->run();

    $fake->assertRan(
        SummarizationAgent::class,
        fn ( mixed $input ): bool => is_array( $input ) && [ 'needle' ] === ( $input['items'] ?? null ),
    );
} );

it( 'fails assertRan when the input callback does not match any run', function (): void {
    $fake = Ai::fake( [
        SummarizationAgent::class => [ 'summary' => 'ok' ],
    ] );

    SummarizationAgent::for( [ 'items' => [ 'haystack' ] ] )->run();

    expect( fn () => $fake->assertRan(
        SummarizationAgent::class,
        fn ( mixed $input ): bool => [ 'needle' ] === ( $input['items'] ?? null ),
    ) )->toThrow( AssertionFailedError::class );
} );

it( 'asserts nothing ran', function (): void {
    $fake = Ai::fake();

    $fake->assertNothingRan();

    Ai::fake( [ SummarizationAgent::class => [ 'summary' => 'ok' ] ] );
    SummarizationAgent::for( [] )->run();

    expect( fn () => Ai::getFake()->assertNothingRan() )
        ->toThrow( AssertionFailedError::class );
} );

it( 'fails assertRanTimes when the count is wrong', function (): void {
    $fake = Ai::fake( [
        SummarizationAgent::class => [ 'summary' => 'ok' ],
    ] );

    SummarizationAgent::for( [] )->run();

    expect( fn () => $fake->assertRanTimes( SummarizationAgent::class, 3 ) )
        ->toThrow( AssertionFailedError::class );
} );
