---
title: Guide
---

# Guide

Practical guides for wiring up the AI package inside a downstream ArtisanPack UI package or a host application.

## Topics

- [Authoring agents](guide/authoring-agents.md) — how a downstream package adds a new AI capability, worked through with `MetaDescriptionAgent` as the running example.
- [Bring your own key (BYOK)](guide/byok.md) — env-mode vs. CMS-mode setup, plus provider-specific notes for Anthropic, OpenAI, Gemini, Groq, and Ollama.
- [Overriding agents](guide/overriding.md) — container binding and config-override patterns for replacing a shipped agent with your own subclass.
- [Trigger surfaces](guide/trigger-surfaces.md) — the shared exception ladder (`HandlesAiFeatureResponses` / `AiFeatureOutcome`) and the Livewire concerns (`ChecksFeatureToggle`, `InteractsWithAiFeature`) every controller and component that runs an agent builds on.
- [Testing](guide/testing.md) — `Ai::fake()` for code that calls agents, `FakeAgentPrompter` for the agents themselves.

See also: [[built-in-agents]] for the agents this package itself ships and [[react-vue-integration]] for consuming the JSON API from JavaScript.
