---
title: Testing
---

# Testing agents and the code that calls them

The package ships two test doubles from `src/Testing`, so they autoload in any package that has `artisanpack-ui/ai` in `require` or `require-dev`. Pick the one that matches what you are testing.

| You are testing | Use |
|---|---|
| Code that *calls* an agent (a controller, a Livewire component, a job) | `Ai::fake()` — skips the whole pipeline and returns scripted output |
| An agent's own `execute()` / prompt construction | `ArtisanPackUI\Ai\Testing\FakeAgentPrompter` — runs the real pipeline against a canned provider response |

## `Ai::fake()` — scripted agent output

`Ai::fake()` installs an `ArtisanPackUI\Ai\Testing\AiFake` on the `artisanpack.ai` singleton. While it is active every `ArtisanPackAgent::run()` returns before the feature gate, credential resolution, cache, budget guard, prompter, and usage event — the run is recorded and a scripted response is returned instead.

```php
use ArtisanPackUI\Ai\Facades\Ai;
use ArtisanPackUI\Seo\Agents\MetaDescriptionAgent;

it( 'stores the suggested meta description', function (): void {
    $fake = Ai::fake( [
        MetaDescriptionAgent::class => [ 'meta_description' => 'A concise summary.' ],
    ] );

    $this->post( route( 'seo.suggest', $post ) )->assertOk();

    $fake->assertRan( MetaDescriptionAgent::class );
    expect( $post->fresh()->meta_description )->toBe( 'A concise summary.' );
} );
```

The array passed to `Ai::fake()` maps an agent class to its **default output** — the `output` array `run()` would normally return, shaped like the agent's `outputSchema()`. It is not the `{ output, input_tokens, output_tokens }` prompter envelope.

### Scripting sequences

```php
$fake = Ai::fake();

$fake->queue( MetaDescriptionAgent::class, [ 'meta_description' => 'first' ] )
     ->queue( MetaDescriptionAgent::class, [ 'meta_description' => 'second' ] )
     ->respondWith( MetaDescriptionAgent::class, [ 'meta_description' => 'default' ] );
```

Queued responses are consumed FIFO, one per run, before the class default. A run for a class that has neither a queued response nor a default throws a `LogicException` naming the agent, so a test that forgot to script an agent fails loudly.

### Assertions

```php
$fake->assertRan( MetaDescriptionAgent::class );
$fake->assertRan( MetaDescriptionAgent::class, fn ( mixed $input ) => 'Hello' === $input['title'] );
$fake->assertRanTimes( MetaDescriptionAgent::class, 2 );
$fake->assertNotRan( AltTextGenerationAgent::class );
$fake->assertNothingRan();

$fake->ran( MetaDescriptionAgent::class ); // array of [ 'agent', 'feature', 'input' ] rows
$fake->runs();                             // every recorded run, in order
```

`Ai::isFaking()` and `Ai::getFake()` report whether a fake is active. The fake lives on the container singleton, so it is discarded with the application between tests — there is nothing to reset.

### What the fake does not do

Because the fake short-circuits before the feature gate, a **disabled feature does not throw `FeatureDisabledException` while a fake is active**. Test toggle behaviour with the real pipeline and `FakeAgentPrompter` (below), or assert on `FeatureRegistry::isToggleOn()` directly. Likewise no `AgentUsageRecorded` event fires and nothing is cached.

## `FakeAgentPrompter` — canned provider responses

Bind `ArtisanPackUI\Ai\Testing\FakeAgentPrompter` over the `AgentPrompter` contract to run the real `run()` pipeline — feature gate, credentials, cache, budget guard, usage event — while replacing only the provider call. This is what the package's own `tests/Feature/Agents/*` use.

```php
use ArtisanPackUI\Ai\Contracts\AgentPrompter;
use ArtisanPackUI\Ai\Testing\FakeAgentPrompter;

beforeEach( function (): void {
    $this->prompter = new FakeAgentPrompter();
    $this->app->instance( AgentPrompter::class, $this->prompter );
} );

it( 'passes the resolved instructions to the provider', function (): void {
    $this->prompter->queue( [ 'meta_description' => 'ok' ], inputTokens: 120, outputTokens: 30 );

    MetaDescriptionAgent::for( [ 'title' => 'Hello', 'body' => '...' ] )->run();

    $call = $this->prompter->calls[0];

    expect( $call['model'] )->toBe( 'claude-haiku-4-5' )
        ->and( $call['instructions'] )->toContain( 'meta description' )
        ->and( $call['tools'] )->toBe( [] );
} );
```

`queue()` takes the output array plus optional token counts (default 100 / 50). Each call to `prompt()` is appended to the public `$calls` array with `credentials`, `model`, `instructions`, `message`, `output_schema`, and `tools`, so you can assert on exactly what your agent would have sent. When the queue is empty the fake returns an empty output with zero tokens.

Packages that copied `FakeAgentPrompter` into their own `tests/Support` before 1.2.0 should delete the copy and import `ArtisanPackUI\Ai\Testing\FakeAgentPrompter` — the local copy no longer satisfies the `AgentPrompter` interface, which gained a `$tools` parameter in 1.2.0.

## Related

- [[authoring-agents]] — the per-agent tests every downstream agent should ship.
- [[trigger-surfaces]] — the shared response handler and Livewire concerns, which are what you usually put behind `Ai::fake()`.
