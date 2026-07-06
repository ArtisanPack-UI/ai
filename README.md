# ArtisanPack UI AI

Shared AI foundation for the ArtisanPack UI ecosystem. Sits alongside `artisanpack-ui/core` and `artisanpack-ui/hooks` as a shared layer that other ArtisanPack UI packages can optionally depend on.

Built on top of [`laravel/ai`](https://github.com/laravel/ai).

See the [AI RFC](https://github.com/ArtisanPack-UI/.github/discussions/8) for design context and the roadmap for downstream feature work.

## Installation

```bash
composer require artisanpack-ui/ai
```

Publish the config:

```bash
php artisan vendor:publish --tag=artisanpack-package-config
```

## Usage

Access the shared AI foundation via the facade or helper:

```php
use ArtisanPackUI\Ai\Facades\Ai;

Ai::/* ... */;

// or
ai()->/* ... */;
```

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
