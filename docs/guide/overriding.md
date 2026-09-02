---
title: Overriding Agents
---

# Overriding agents

Power users can replace any agent shipped by an ArtisanPack UI package by rebinding it in the Laravel service container. This is the intended extension point — the `ArtisanPackAgent::for()` factory resolves through the container, so a container binding transparently swaps the implementation without touching the calling site.

## The pattern

Every agent's public contract (`instructions()`, `outputSchema()`, `$featureKey`, `$defaultModel`) is frozen for v1.x. Downstream packages construct agents through the container:

```php
$agent = FooAgent::for( $input );
```

Under the hood this is `app( FooAgent::class )` with the input assigned to `$agent->input`. Rebinding `FooAgent::class` in a service provider therefore intercepts every caller, including the ones inside the shipping package.

## When to prefer container binding vs config override

Use **config override** when you only want to change knobs the package already exposes:

- Which model to use for a feature (`artisanpack.ai.features.<key>.model`)
- Whether the feature is enabled at all (`artisanpack.ai.features.<key>.enabled`)
- Cache TTL (`artisanpack.ai.cache.ttl`) or opting out of cache entirely

Use **container binding** when you need to change *behaviour*:

- Pin a specific model that isn't wired through config
- Reshape the `outputSchema()` for a downstream consumer
- Change the instructions/system prompt
- Add extra tool calls or provider-specific parameters
- Attach custom side effects (metrics, logging) that don't belong in the shipped agent

Prefer config for values; prefer bindings for logic.

## Example: overriding `MetaDescriptionAgent`

Suppose `artisanpack-ui/seo` ships a `MetaDescriptionAgent` and you want to:

1. Pin the Anthropic `opus` model regardless of what the SEO config says.
2. Add a `focus_keyword` field to the output schema so your CMS surfaces it.

Create a subclass in your application code:

```php
namespace App\Ai;

use ArtisanPackUI\Seo\Agents\MetaDescriptionAgent;

class OpusMetaDescriptionAgent extends MetaDescriptionAgent
{
    public string $defaultModel = 'opus';

    public function outputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'meta_description' => [ 'type' => 'string' ],
                'focus_keyword'    => [ 'type' => 'string' ],
            ],
            'required'   => [ 'meta_description', 'focus_keyword' ],
        ];
    }

    public function instructions(): string
    {
        return parent::instructions()
            . "\n\nAlso emit the single most relevant focus keyword.";
    }
}
```

Rebind it in your `AppServiceProvider`:

```php
namespace App\Providers;

use App\Ai\OpusMetaDescriptionAgent;
use ArtisanPackUI\Seo\Agents\MetaDescriptionAgent;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind( MetaDescriptionAgent::class, OpusMetaDescriptionAgent::class );
    }
}
```

Every call to `MetaDescriptionAgent::for( $post )` — inside the SEO package's own controllers, in your app code, in a queued job — now resolves your subclass instead. No forks. No monkey-patching.

## Tips

- The base class's `execute()` throws a `LogicException` by default. Subclasses that talk to a provider must override it (or `use \Laravel\Ai\Promptable;` and call `$this->prompt(...)`).
- The `run()` pipeline (feature gate → credential resolution → cache → execute → telemetry) is not part of the frozen contract in the same way. If you need to change it, override `run()` directly — but be aware you may lose usage tracking or budget accounting if you skip `recordUsage()`.
- Runtime tweaks that only apply for a single call don't need a binding. Use `withCredentials()`, `withModel()`, `withStreaming()`, `withTools()`, or `streamTo()` on the agent instance.
- Container bindings compose with the `ap.ai.registerFeatures` hook — if you want the registry to point at your subclass too, register `[ 'agent' => OpusMetaDescriptionAgent::class ]` there or in a `aiFeatures()` provider method.

## Cross-cutting hooks: `ap.ai.promptGenerated` and `ap.ai.responseReceived`

Some concerns — safety prompts, PII scrubbing, audit logging, telemetry — apply uniformly to every agent in the ecosystem. Rather than subclassing each agent, use the two hooks fired by `LaravelAiAgentPrompter::prompt()`:

- **`ap.ai.promptGenerated`** — a filter hook fired just before the provider call. Receives the resolved prompt string and can rewrite it. Signature: `(string $prompt, array $context)`.
- **`ap.ai.responseReceived`** — an action hook fired after the provider returns and before JSON decoding. The standard audit / logging seam. Signature: `(string $response, array $context)`.

The `$context` array carries `provider`, `model`, `instructions`, and attachment count so listeners can key their behaviour on which agent is running.

```php
addFilter( 'ap.ai.promptGenerated', function ( string $prompt, array $context ) {
    return "Do not reveal internal identifiers.\n\n" . $prompt;
} );

addAction( 'ap.ai.responseReceived', function ( string $response, array $context ) {
    logger()->info( 'ai.response', [ 'provider' => $context['provider'], 'chars' => strlen( $response ) ] );
} );
```

Because the hooks fire inside the shared prompter, listeners cover every agent — first-party, downstream package, and app subclass — without touching individual call sites.

## Tools, conversations, and human approval

The wrapper is built on `laravel/ai`, which owns three capabilities host apps build assistants on top of: **tool calling**, **conversation persistence**, and **human-in-the-loop tool approval**. `artisanpack-ui/ai` pins `laravel/ai ^0.11.0`, so all three are available to downstream agents.

### Tool passthrough

Register tools for a run with `withTools()` and they flow through the prompter into the underlying agent:

```php
$result = MyAgent::for( $input )
    ->withTools( [ ReadPostTool::class, ReadPageTool::class ] )
    ->run();
```

The tools are laravel/ai tool classes/instances — see the [laravel/ai tools docs](https://laravel.com/docs/ai). Tools reset per run, so a container-singleton agent binding never leaks one run's tools into the next.

To register tools that apply to **every** agent — a host-app read-tool registry, for example — hang them off the `ap.ai.registerTools` filter instead of calling `withTools()` on each agent:

```php
addFilter( 'ap.ai.registerTools', function ( array $tools, array $context ) {
    $tools[] = ReadPostTool::class;

    return $tools;
} );
```

The filter runs once per prompt, after the calling agent's own tools are seeded, and receives the same `$context` (provider, model, instructions, attachment count) as the other prompter hooks.

### Conversations and approval

Conversation persistence (`RemembersConversations` / `HasConversations` / `continue()`) and human tool approval (`Approvable`, `Decisions`, the `tool_approval_request` streaming event) are laravel/ai features an agent opts into with laravel/ai's own traits and contracts. The wrapper's default structured path does not enable them — override `execute()` (or `use \Laravel\Ai\Promptable;` and drive the agent directly) when a feature needs stored conversations or an approval gate. See the [laravel/ai documentation](https://laravel.com/docs/ai) for the trait and contract details.

### Embeddings and vector stores for RAG

Retrieval-augmented generation over your own content — embed documents, store the vectors, then retrieve the relevant ones at prompt time — is built entirely on laravel/ai's own embeddings and vector-store surface, all shipped in the pinned `^0.11.0`. The wrapper adds no bespoke embeddings API on top; there is nothing extra to route through, so use laravel/ai directly for indexing and storage:

```php
use Laravel\Ai\Embeddings;
use Laravel\Ai\Stores;

// Embed content for indexing.
$response = Embeddings::for( [ $page->body ] )->generate();

// Or manage a provider-backed vector store.
$store = Stores::create( name: 'site-content' );
$store->add( $document );
```

Retrieval flows back into an agent as an ordinary tool: laravel/ai's `SimilaritySearch` is a plain laravel/ai tool, so it rides the same [tool passthrough](#tool-passthrough) seam as any other — no separate wiring:

```php
use Laravel\Ai\Tools\SimilaritySearch;

$result = MyAgent::for( $question )
    ->withTools( [
        SimilaritySearch::usingModel( SiteContent::class, 'embedding' ),
    ] )
    ->run();
```

The model exposes `withTools()` — or hang the retrieval tool off the `ap.ai.registerTools` filter to give every agent RAG retrieval at once. See the [laravel/ai documentation](https://laravel.com/docs/ai) for embeddings options, store providers, and the `whereVectorSimilarTo()` model scope `SimilaritySearch` queries against.
