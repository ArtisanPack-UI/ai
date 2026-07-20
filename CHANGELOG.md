# Changelog

All notable changes to `artisanpack-ui/ai` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.1.0] - 2026-07-20

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
