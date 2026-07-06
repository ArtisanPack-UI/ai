---
title: Authoring Agents
---

# Authoring an agent

An **agent** is a class that wraps a single AI capability — write a meta description, generate alt text, classify a support ticket — into a self-contained unit with a frozen contract. Every ArtisanPack UI package that ships an AI feature does it by shipping an agent.

This guide walks the SEO package's `MetaDescriptionAgent` end to end. It's the canonical worked example the RFC calls out, and it demonstrates every extension point the base class offers.

## The frozen contract

Every agent extends `ArtisanPackUI\Ai\Agents\ArtisanPackAgent`. The five surface members are stable across the v1.x line:

```php
public string $featureKey;     // e.g. 'seo.suggest_meta_description'
public string $package;        // e.g. 'artisanpack-ui/seo'
public string $defaultModel;   // e.g. 'claude-3-5-haiku-latest'

public function instructions(): string;
public function outputSchema(): array;
```

Everything else — how the agent is instantiated, how credentials are resolved, how usage is tracked, how caching works — belongs to the base class and is not part of the contract you implement.

## Step 1: subclass `ArtisanPackAgent`

```php
namespace ArtisanPackUI\Seo\Agents;

use ArtisanPackUI\Ai\Agents\ArtisanPackAgent;
use Laravel\Ai\Promptable;

class MetaDescriptionAgent extends ArtisanPackAgent
{
    use Promptable;

    public string $featureKey   = 'seo.suggest_meta_description';
    public string $package      = 'artisanpack-ui/seo';
    public string $defaultModel = 'claude-3-5-haiku-latest';

    public function instructions(): string
    {
        return <<<'PROMPT'
            You are an SEO assistant. Given a page's title and body, propose
            a meta description under 155 characters. Prioritise clarity over
            keyword density.
            PROMPT;
    }

    public function outputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'meta_description' => [ 'type' => 'string', 'maxLength' => 160 ],
            ],
            'required'   => [ 'meta_description' ],
        ];
    }
}
```

The `Promptable` trait ships with `laravel/ai` and gives you `$this->prompt(...)` for the actual provider call. The base class then handles feature gating, credential lookup, caching, telemetry, and event dispatch on top of it.

## Step 2: register the feature

Two paths — pick whichever fits your package:

### Option A: from your service provider (RFC-frozen fallback)

```php
namespace ArtisanPackUI\Seo;

use ArtisanPackUI\Seo\Agents\MetaDescriptionAgent;
use Illuminate\Support\ServiceProvider;

class SeoServiceProvider extends ServiceProvider
{
    public function aiFeatures(): array
    {
        return [
            'seo.suggest_meta_description' => [
                'agent'       => MetaDescriptionAgent::class,
                'label'       => 'Meta description suggestions',
                'description' => 'Proposes a meta description for a page under 155 characters.',
                'package'     => 'artisanpack-ui/seo',
            ],
        ];
    }
}
```

The AI service provider walks every loaded provider looking for an `aiFeatures()` method, so this alone is enough — no additional wiring required.

### Option B: via the `ap.ai.register-features` filter hook

Prefer this when the registering code doesn't own a service provider (e.g. an application bootstrapping bespoke agents):

```php
use ArtisanPackUI\Ai\Contracts\FeatureRegistry;

addFilter( 'ap.ai.register-features', function ( FeatureRegistry $registry ) {
    $registry->register(
        'seo.suggest_meta_description',
        MetaDescriptionAgent::class,
        [ 'package' => 'artisanpack-ui/seo' ],
    );

    return $registry;
} );
```

Either path lands the agent in the same registry, which powers the admin toggle page and the JSON API.

## Step 3: call the agent

At the call site — a Livewire component, a controller, a queued job — the invocation is:

```php
$suggestion = MetaDescriptionAgent::for( [
    'title' => $post->title,
    'body'  => $post->summary_or_body,
] )->run();

$post->update( [ 'meta_description' => $suggestion['meta_description'] ] );
```

`::for()` is a container-aware factory: it resolves through `app( MetaDescriptionAgent::class )` and assigns the input. Any container binding for `MetaDescriptionAgent::class` will transparently swap the implementation — see [overriding.md](overriding.md) for the pattern.

`->run()` runs the full pipeline:

1. Feature gate check (skips + throws `FeatureDisabledException` when the toggle is off)
2. Credential resolution via the shared `CredentialResolver`
3. Cache lookup (SHA-256 of `(feature_key, model, input)` → cached JSON)
4. `execute()` — your `prompt()` call
5. `recordUsage()` — fires `AgentUsageRecorded` for the usage dashboard + budget accounting
6. Cache store on success

If you need to bypass the pipeline for a specific call — e.g. dry-running the prompt in a test — call `execute()` directly.

## Step 4: add per-agent tests

Follow the pattern in `tests/Feature/Agents/`. At minimum:

- One test that seeds credentials, runs the agent, and asserts the output schema shape.
- One test that turns the feature off and asserts `FeatureDisabledException` is thrown.
- One test that verifies `AgentUsageRecorded` is dispatched with the expected feature key + model.

The base class ships with the plumbing to make these easy — see `tests/Support/FakeAgent.php` for a minimal template.

## What you don't need to do

The base class handles the following automatically. Don't reimplement them in your agent:

- Reading the API key or provider from the store (use `$this->credentials` if you need to inspect the resolved credentials).
- Deciding whether the feature is enabled (`isEnabled()` runs before `execute()`).
- Emitting the `AgentUsageRecorded` event.
- Applying per-feature model overrides (`config('artisanpack.ai.features.<key>.model')` and the admin's advanced-tab override both slot in through `resolveModel()`).
- Enforcing the monthly cost cap (checked before the provider call in `run()`).

## Convention notes

- **Feature keys** are dot-notation slugs of the form `{package-slug}.{action}`. Keep them stable — they're what users reference in config overrides and admin toggles.
- **Package names** should match the composer package name (`artisanpack-ui/seo`, not `seo`). The admin toggle page groups on this string.
- **Default models** should be the cheapest model that hits the quality bar for the feature. Users pin something smarter via override.
- **Output schemas** should return objects with named properties, not raw strings. Downstream consumers rely on `$result['meta_description']` — not `$result` as an unstructured value.

## Related

- [byok.md](byok.md) — where credentials come from (env, config, admin UI).
- [overriding.md](overriding.md) — replace a shipped agent with your own subclass.
