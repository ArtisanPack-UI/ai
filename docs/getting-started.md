---
title: Getting Started
---

# Getting Started

Welcome to ArtisanPack UI AI. This guide will get you installed and running your first agent.

## Installation

```bash
composer require artisanpack-ui/ai
php artisan migrate
```

Publish the config if you want to customise defaults:

```bash
php artisan vendor:publish --tag=artisanpack-package-config
```

## Wire up a credential

The package never ships credentials. Either drop them in `.env` (env mode) or let an administrator enter them through the admin UI (CMS mode). See [[byok]] for the full breakdown of both modes and per-provider notes for Anthropic, OpenAI, Gemini, Groq, and Ollama.

Minimum env-mode setup for Anthropic:

```dotenv
ARTISANPACK_AI_PROVIDER=anthropic
ANTHROPIC_API_KEY=sk-ant-...
```

## Run an agent

Access the shared foundation via the facade or helper:

```php
use ArtisanPackUI\Ai\Facades\Ai;

Ai::/* ... */;

// or
ai()->/* ... */;
```

Run an agent shipped by any ecosystem package (this example uses `artisanpack-ui/seo`):

```php
use ArtisanPackUI\Seo\Agents\MetaDescriptionAgent;

$suggestion = MetaDescriptionAgent::for( [
    'title' => $post->title,
    'body'  => $post->summary_or_body,
] )->run();

$post->update( [ 'meta_description' => $suggestion['meta_description'] ] );
```

That single call runs the full pipeline: feature-gate check → credential resolution → cache lookup → provider call → telemetry event → cache store. There's no separate setup on the calling side.

## Next steps

- [[authoring-agents]] — build your own agent inside a downstream package.
- [[built-in-agents]] — the cross-cutting agents this package ships.
- [[overriding]] — replace a shipped agent with your own subclass.
- [[react-vue-integration]] — consume the JSON API from a JavaScript client.
