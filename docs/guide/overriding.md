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
- Runtime tweaks that only apply for a single call don't need a binding. Use `withCredentials()`, `withModel()`, `withStreaming()`, or `streamTo()` on the agent instance.
- Container bindings compose with the `ap.ai.registerFeatures` hook — if you want the registry to point at your subclass too, register `[ 'agent' => OpusMetaDescriptionAgent::class ]` there or in a `aiFeatures()` provider method.

## Cross-cutting hooks: `ap.ai.promptGenerated` and `ap.ai.responseReceived`

Some concerns — safety prompts, PII scrubbing, audit logging, telemetry — apply uniformly to every agent in the ecosystem. Rather than subclassing each agent, use the two hooks fired by `LaravelAiAgentPrompter::prompt()`:

- **`ap.ai.promptGenerated`** — a filter hook fired just before the provider call. Receives the resolved prompt string and can rewrite it. Signature: `(string $prompt, array $context)`.
- **`ap.ai.responseReceived`** — an action hook fired after the provider returns and before JSON decoding. The standard audit / logging seam. Signature: `(string $response, array $context)`.

The `$context` array carries `provider`, `model`, `instructions`, and attachment count so listeners can key their behaviour on which agent is running.

```php
use function ArtisanPackUI\Hooks\{addFilter, addAction};

addFilter( 'ap.ai.promptGenerated', function ( string $prompt, array $context ) {
    return "Do not reveal internal identifiers.\n\n" . $prompt;
} );

addAction( 'ap.ai.responseReceived', function ( string $response, array $context ) {
    logger()->info( 'ai.response', [ 'provider' => $context['provider'], 'chars' => strlen( $response ) ] );
} );
```

Because the hooks fire inside the shared prompter, listeners cover every agent — first-party, downstream package, and app subclass — without touching individual call sites.
