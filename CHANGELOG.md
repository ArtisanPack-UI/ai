# Changelog

All notable changes to `artisanpack-ui/ai` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Hard budget-cap enforcement with a critical-agent bypass. When `artisanpack.ai.budget.enforce_hard_cap` is enabled (off by default) and month-to-date spend reaches the configured monthly cap, `ArtisanPackAgent::run()` throws a new `BudgetExceededException` before making the paid provider call — cache hits still serve, since they cost nothing. Agents that set the new `public bool $critical = true;` surface member bypass the cap and log a warning line instead, so safety-critical work (spam detection, moderation) keeps running past the cap. This makes the "monthly cost cap enforced in `run()`" behaviour the authoring-agents guide already documented actually hold.
- Tool passthrough for agents. `ArtisanPackAgent::withTools()` registers laravel/ai tool classes/instances for the next run, forwarded through `AgentPrompter::prompt()` into the underlying agent so host-app-registered tools (e.g. Keystone's read-tool registry) flow through the pipeline. Tools reset per run alongside the other run-scoped fields.
- New `ap.ai.registerTools` filter hook, applied inside `LaravelAiAgentPrompter` once per prompt. The uniform seam a host app hangs its tool registry off of so registered tools reach every agent without each agent opting in. Signature: `(array $tools, array $context)`.
- Verified the laravel/ai embeddings + vector-store surface Keystone's Phase 2 RAG over site content depends on (§8.6). Embeddings generation (`Embeddings::for()`), vector stores (`Stores` / `Store`), and the `SimilaritySearch` retrieval tool all ship in the pinned `^0.11.0` and need no bespoke wrapper API — retrieval rides the existing `withTools()` tool-passthrough seam. Added upstream-capabilities guards so a future constraint relaxation fails loudly, plus a test pinning a `SimilaritySearch` tool through the seam, and an "Embeddings and vector stores for RAG" section to the overriding guide.

### Changed

- Bumped `laravel/ai` to `^0.11.0` (was `^0.8`). This exposes the human-in-the-loop tool approval surface (`Approvable`, `Decisions`, and the `tool_approval_request` streaming event) added upstream in v0.10.0, alongside the conversation-persistence concerns (`RemembersConversations` / `HasConversations` / `continue()`) that were already present. Downstream agents opt into conversations and approval via laravel/ai's own traits; the wrapper's default structured path is unchanged apart from the new tool passthrough. A new upstream-capabilities test guards these features so a future constraint relaxation fails loudly.
- Moved the default Anthropic pricing table off the retired Claude 3.x family (Claude 3.5 Haiku/Sonnet and Claude 3 Opus). `claude-3-5-haiku`, `claude-3-5-sonnet`, and `claude-3-opus` are replaced by `claude-haiku-4-5` (cheap utility agents), `claude-sonnet-5` (assistant + long-form), and `claude-opus-5` (premium). The bare `haiku`/`sonnet`/`opus` aliases are retained and repriced to match. BYOK and authoring-agents docs now recommend the current-generation model ids. Host apps can still override defaults per-feature without upstream changes.

## [1.1.0] - 2026-07-21

### Added

- New `ap.ai.promptGenerated` filter hook, fired inside `LaravelAiAgentPrompter::prompt()` before the provider call. Lets apps inject safety prompts, scrub PII, or add context uniformly across every agent. Signature: `(string $prompt, array $context)`.
- New `ap.ai.responseReceived` action hook, fired after the provider returns and before JSON decoding. The standard audit / logging seam. Signature: `(string $response, array $context)`.

### Changed

- Renamed hook `ap.ai.register-features` → `ap.ai.registerFeatures` to align with cross-package hooks convention. Old name registered as a deprecation alias. Alias removal deferred to next major.
- Bumped `artisanpack-ui/hooks` to `^1.3`.

## [1.0.0] - 2026-07-06

Initial stable release of the shared AI foundation for the ArtisanPack UI ecosystem, built on top of `laravel/ai`.

### Added

- `ArtisanPackAgent` base class, feature registry, and credential resolver providing the foundation every downstream AI-powered package builds on.
- Encrypted credential storage with a chained resolver so credentials can be sourced from settings, config, or environment.
- Usage tracking pipeline: `ai_usage_events` table, `PersistAgentUsage` listener, `AiUsageRepository`, and a `PurgeUsageEventsJob` for retention.
- Streaming support via `AgentStreamResponse` for token-by-token responses to the client.
- Budget tracking with `CostEstimator`, `BudgetSettings`, `BudgetThresholdCrossed` event, `CheckBudgetThresholdJob`, and `BudgetWarningMail`.
- Cross-cutting agents shipped in the core package: `AltTextGenerationAgent`, `ContentRewriteAgent`, and `SummarizationAgent`.
- Admin surface: `AiSettings`, `FeatureToggles`, and `UsageDashboard` Livewire components for the ArtisanPack UI admin panel.
- Config-based prompt and model overrides so downstream apps can tune agents without subclassing.
- Ollama as a first-class provider — every shipped agent works against Ollama alongside a cloud provider so self-hosted deployments never pay per-token fees.
- JSON API (`routes/api.php`) for settings, features, usage, and connection testing with an `AbstractAdminController` base.
- `ConnectionTester` support utility and `RotateAiCredentialsCommand` artisan command.
- Documentation restructured into `docs/` with getting-started, guide, integration (including React and Vue examples), and reference sections.

[Unreleased]: https://github.com/ArtisanPack-UI/ai/compare/v1.1.0...HEAD
[1.1.0]: https://github.com/ArtisanPack-UI/ai/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/ArtisanPack-UI/ai/releases/tag/v1.0.0
