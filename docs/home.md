---
title: ArtisanPack UI AI
---

# ArtisanPack UI AI

Shared AI foundation for the ArtisanPack UI ecosystem. Sits alongside `artisanpack-ui/core` and `artisanpack-ui/hooks` as a shared layer that other ArtisanPack UI packages can optionally depend on. Built on top of [`laravel/ai`](https://github.com/laravel/ai).

## What's in this package

- A **feature registry** — every AI capability across the ecosystem discoverable in one place, with per-feature enable/disable toggles that survive across processes.
- A **credential store** — bring your own key via `.env` or the admin UI, encrypted at rest, resolved through a single `CredentialResolver` contract.
- A **cost + usage layer** — per-agent event stream, monthly budget cap, dashboard aggregations, budget-warning email.
- A **provider-agnostic agent base class** — subclass, declare a feature key + output schema, get caching, telemetry, and streaming for free.
- **Livewire admin surfaces** — Settings page, Usage dashboard, Per-feature toggles page.
- **JSON API endpoints** — REST parity for React and Vue starter kits.
- **Ollama support** — every shipped agent works against a self-hosted local model, not just the cloud providers.

## Documentation

- [Getting Started](getting-started.md) — install, publish config, and run your first agent.
- [Guide](guide.md) — author your own agents, override shipped ones, and wire up credentials.
- [Reference](reference.md) — built-in agents shipped by this package and the OpenAPI schema for the JSON API.
- [Integration](integration.md) — consume the JSON API from `@artisanpack-ui/react` and `@artisanpack-ui/vue`.

## Related packages

- [[core]] — shared utilities and helpers.
- [[hooks]] — WordPress-style actions and filters used by the AI event stream.
- [[seo]] — the canonical downstream consumer that ships `MetaDescriptionAgent`.
