---
title: Bring Your Own Key (BYOK)
---

# Bring your own key (BYOK)

The AI package never ships credentials. Every deployment either wires its own via environment variables (env mode) or lets an administrator drop them into the admin UI (CMS mode). This doc covers both, plus the specifics of each supported provider.

## Two modes, one resolver

Credentials flow through `ArtisanPackUI\Ai\Contracts\CredentialResolver`. The default implementation, `ChainedCredentialResolver`, tries sources in this order:

1. **Per-call override** — `$agent->withCredentials(new Credentials(...))->run()`. Never leaves the request. Used by tests and one-off callers.
2. **Settings store** — the encrypted `settings` row managed by the admin UI. Present when cms-framework's Settings table exists and a key has been saved.
3. **Config** — `config('artisanpack.ai.providers.<slug>')`, which reads from environment variables via the shipped defaults.

The first source that yields a credential wins. There is no fallback merging — if the store has a key, the config `.env` is ignored for that provider.

## Env mode

Suitable when only a single fixed provider is needed and credentials rotate via re-deploy rather than a UI.

```env
ARTISANPACK_AI_PROVIDER=anthropic
ARTISANPACK_AI_DEFAULT_MODEL=claude-3-5-haiku-latest

ANTHROPIC_API_KEY=sk-ant-…
OPENAI_API_KEY=sk-…
GEMINI_API_KEY=…
GROQ_API_KEY=…

# Ollama has no API key — set only the base URL.
ARTISANPACK_AI_OLLAMA_BASE_URL=http://127.0.0.1:11434
```

No further wiring required. Every agent picks up the resolved credentials on first `run()`.

## CMS mode

Suitable when the operator of the CMS is not the developer of the CMS — a hosted install, a client project, a self-service SaaS tenant. Credentials are entered through the admin UI at `Admin → Packages → AI → Settings` and encrypted at rest via Laravel's `Encrypter`.

Ensure cms-framework is installed:

```bash
composer require artisanpack-ui/cms-framework
```

Then run migrations to create the `settings` table:

```bash
php artisan migrate
```

Once the table exists the AI settings page saves credentials to it automatically. The store never returns the plaintext key over an API or Livewire snapshot — read paths only expose whether a key is present.

### Rotating in CMS mode

```bash
php artisan artisanpack-ai:rotate-credentials
```

Prompts for the new provider/key/model and updates the encrypted row in place. Combine with `php artisan cache:clear` if your app caches the resolver result.

## Provider notes

### Anthropic

- Sign up: <https://console.anthropic.com/>
- Env var: `ANTHROPIC_API_KEY`
- Recommended models: `claude-3-5-haiku-latest` (cheap), `claude-3-5-sonnet-latest` (default), `claude-opus-4-latest` (premium).
- Connection test: `GET https://api.anthropic.com/v1/models` with the key attached.
- Streaming: fully supported via `withStreaming()` on the agent.

### OpenAI

- Sign up: <https://platform.openai.com/api-keys>
- Env var: `OPENAI_API_KEY`
- Recommended models: `gpt-4o-mini` (cheap), `gpt-4o` (default), `gpt-4.1` (premium).
- Connection test: `GET https://api.openai.com/v1/models`.
- Streaming: fully supported.

### Google (Gemini)

- Sign up: <https://aistudio.google.com/apikey>
- Env var: `GEMINI_API_KEY`
- Recommended models: `gemini-2.0-flash-lite` (cheap), `gemini-2.5-flash` (default), `gemini-2.5-pro` (premium).
- Connection test: shipped as unsupported — the free-tier API doesn't have a stable liveness endpoint. Save proceeds without a probe.

### Groq

- Sign up: <https://console.groq.com/>
- Env var: `GROQ_API_KEY`
- Recommended models: `llama-3.3-70b-versatile` (default), `mixtral-8x7b-32768` (long context).
- Notably fast (Groq is a specialised inference accelerator), which makes it a strong pick for latency-sensitive agents like inline suggestions.
- Connection test: shipped as unsupported; save proceeds without a probe.

### Ollama (local, no key)

- Install: <https://ollama.com/>
- No API key. Set `ARTISANPACK_AI_OLLAMA_BASE_URL` (env mode) or fill in the Base URL field (CMS mode).
- Recommended models: `llama3.2:1b`, `llama3.2:3b`, `qwen2.5:7b`. See the [README's local-models section](../../README.md#local-models-ollama) for pull commands and per-agent recommendations.
- Connection test: `GET {base_url}/api/tags`. Free and safe to hit on every save.

## Security posture

- API keys are encrypted at rest via `Illuminate\Contracts\Encryption\Encrypter`. The encryption key is `APP_KEY` — rotating it requires re-saving the credentials afterwards.
- The credential store is memoised per-request but re-resolves the encrypter lazily, so an `APP_KEY` rotation during a running worker is picked up on the next call rather than serving stale ciphertext.
- The plaintext key is never returned from the JSON API, the Livewire snapshot, or the admin's Settings serialiser. If you're extending either surface, use `SettingsCredentialStore::toPublicArray()` — it returns only the `api_key_present` boolean.

## Related

- [overriding.md](overriding.md) — bind a bespoke resolver if you need to source credentials from a different store (Vault, AWS Secrets Manager, etc.).
- [authoring-agents.md](authoring-agents.md) — how downstream packages ship agents that consume these credentials.
