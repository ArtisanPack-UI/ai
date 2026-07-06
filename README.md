# ArtisanPack UI AI

Shared AI foundation for the ArtisanPack UI ecosystem. Sits alongside `artisanpack-ui/core` and `artisanpack-ui/hooks` as a shared layer that other ArtisanPack UI packages can optionally depend on.

Built on top of [`laravel/ai`](https://github.com/laravel/ai).

See the [AI RFC](https://github.com/ArtisanPack-UI/.github/discussions/8) for design context and the roadmap for downstream feature work.

## What this package gives you

- A **feature registry** — every AI capability across the ecosystem discoverable in one place, with per-feature enable/disable toggles that survive across processes.
- A **credential store** — bring your own key via `.env` or the admin UI, encrypted at rest, resolved through a single `CredentialResolver` contract.
- A **cost + usage layer** — per-agent event stream, monthly budget cap, dashboard aggregations, budget-warning email.
- A **provider-agnostic agent base class** — subclass, declare a feature key + output schema, get caching, telemetry, and streaming for free.
- **Livewire admin surfaces** — Settings page, Usage dashboard, Per-feature toggles page.
- **JSON API endpoints** — REST parity for React and Vue starter kits (see [docs/api-schema.json](docs/api-schema.json)).
- **Ollama support** — every shipped agent works against a self-hosted local model, not just the cloud providers.

## Installation

```bash
composer require artisanpack-ui/ai
php artisan migrate
```

Publish the config if you want to customise defaults:

```bash
php artisan vendor:publish --tag=artisanpack-package-config
```

## Quick start

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

## Documentation

- **[Authoring agents](docs/authoring-agents.md)** — how a downstream package adds a new AI capability, worked through with `MetaDescriptionAgent` as the running example.
- **[Bring your own key (BYOK)](docs/byok.md)** — env-mode vs. CMS-mode setup, provider-specific notes for Anthropic, OpenAI, Gemini, Groq, and Ollama.
- **[Overriding](docs/overriding.md)** — container binding and config override patterns for replacing a shipped agent with your own subclass.
- **[JSON API schema](docs/api-schema.json)** — OpenAPI 3.1 schema for the REST endpoints that back the React and Vue admin surfaces.

## Admin surfaces

Once cms-framework is installed and migrations are run, the following surfaces register automatically under `Admin → Packages → AI`:

- **Settings** — provider, encrypted API key, base URL (for Ollama), default model, per-feature overrides.
- **Usage** — token totals, per-feature cost breakdown, daily buckets, drilldown to individual events.
- **Features** — every registered agent listed and grouped by owning package, with an on/off toggle per feature.

Each page is capability-gated on `manage_ai_settings`. cms-framework wires the capability; other stacks must define it themselves.

## JSON API

The React and Vue starter kits consume the same data through REST endpoints so they don't have to depend on Livewire. All endpoints are Sanctum-authenticated by default and gated on the same `manage_ai_settings` ability.

| Method | Path                                                | Purpose                             |
|--------|-----------------------------------------------------|-------------------------------------|
| GET    | `/api/artisanpack-ai/settings`                      | Read current settings (no plaintext key) |
| PUT    | `/api/artisanpack-ai/settings`                      | Update credentials + overrides      |
| GET    | `/api/artisanpack-ai/features`                      | List registered features            |
| POST   | `/api/artisanpack-ai/features/{key}/toggle`         | Enable or disable a feature         |
| GET    | `/api/artisanpack-ai/usage?from=…&to=…`             | Aggregations for the dashboard      |
| POST   | `/api/artisanpack-ai/test-connection`               | Probe the provider without saving   |

Customise the prefix, middleware, and ability via `config('artisanpack.ai.api')`. Full schema in [docs/api-schema.json](docs/api-schema.json).

## Local models (Ollama)

Ollama is a first-class provider in v1.0.0. Every downstream package that ships an agent is expected to work against Ollama in addition to a cloud provider, so a self-hosted CMS can run without ever paying per-token fees.

### 1. Install and start Ollama

```bash
# macOS
brew install ollama
ollama serve                     # starts the daemon on http://127.0.0.1:11434

# Pull a model. Recommendations by workload:
ollama pull llama3.2:1b          # tiny / fast: alt-text, short summaries
ollama pull llama3.2:3b          # balanced default for most agents
ollama pull qwen2.5:7b           # smarter, still comfortable on a laptop
ollama pull llama3.1:70b         # cloud-class quality if you have the RAM
```

### 2. Point the ai package at Ollama

Either flip the environment file:

```env
ARTISANPACK_AI_PROVIDER=ollama
ARTISANPACK_AI_BASE_URL=http://127.0.0.1:11434
ARTISANPACK_AI_DEFAULT_MODEL=llama3.2:3b
# No API key required for local Ollama.
```

...or select **Ollama** in the AI Settings admin page (`Admin → Packages → AI → Settings`). The admin UI prompts for a base URL instead of an API key when Ollama is chosen, and the "Test connection" button probes `GET {base_url}/api/tags` before saving.

### 3. Recommended models per feature

The following ArtisanPack UI agents have been validated end-to-end against a local Ollama daemon:

| Agent (feature key)                        | Ollama model    | Notes                                                          |
|--------------------------------------------|-----------------|----------------------------------------------------------------|
| `seo.suggest_meta_description`             | `llama3.2:3b`   | ~5s per suggestion on an M1; identical schema to Anthropic.    |
| `media.generate_alt_text`                  | `llama3.2:1b`   | Vision-free path — pass the filename + caption context.        |
| `cms.summarize_content`                    | `qwen2.5:7b`    | Higher token budget benefits from the sharper model.           |

Override the recommendation per feature via config or the admin's advanced-tab per-feature model selector:

```php
// config/artisanpack/ai.php
'features' => [
    'seo.suggest_meta_description' => [
        'model' => 'qwen2.5:7b',       // pin a smarter model
    ],
],
```

### 4. Local integration test

The package ships a Pest test suite that exercises `ConnectionTester` with a stubbed Ollama response. To run the real end-to-end suite against your daemon, set:

```bash
ARTISANPACK_AI_OLLAMA_E2E=1 \
ARTISANPACK_AI_BASE_URL=http://127.0.0.1:11434 \
./vendor/bin/pest --group=ollama-e2e
```

CI leaves `ARTISANPACK_AI_OLLAMA_E2E` unset — the gated group is skipped when no daemon is reachable so pipelines stay green on hosted runners.

## Contributing

As an open source project, this package is open to contributions from anyone. Please [read through the contributing guidelines](CONTRIBUTING.md) to learn more about how you can contribute.
