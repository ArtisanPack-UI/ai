<?php

/**
 * ArtisanPack UI AI configuration.
 *
 * Skeleton configuration for the shared AI foundation. Downstream packages
 * consume these values via the `artisanpack.ai` config key after merge.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Default Provider
    |--------------------------------------------------------------------------
    |
    | The default AI provider used when a caller does not specify one. The
    | value should match a key defined under `providers` below. Downstream
    | foundation classes will resolve credentials and defaults from that
    | provider entry.
    |
    */

    'default' => env( 'ARTISANPACK_AI_PROVIDER', 'anthropic' ),

    /*
    |--------------------------------------------------------------------------
    | Top-Level Credentials
    |--------------------------------------------------------------------------
    |
    | Optional shared credentials used by the CredentialResolver's env branch.
    | Env vars take precedence; these config keys act as a fallback for
    | environments where env vars aren't available (e.g. hosted docs sites).
    | Do NOT commit real API keys to source control.
    |
    */

    'api_key'       => env( 'ARTISANPACK_AI_API_KEY' ),
    'default_model' => env( 'ARTISANPACK_AI_DEFAULT_MODEL' ),
    'base_url'      => env( 'ARTISANPACK_AI_BASE_URL' ),

    /*
    |--------------------------------------------------------------------------
    | Cache
    |--------------------------------------------------------------------------
    |
    | Agent responses can be cached by (feature, model, input) content hash to
    | avoid redundant provider calls. Set `store` to route to a dedicated
    | cache store (falls back to the default when null). Individual agents
    | may opt out with `public bool $cacheable = false;` or override the TTL
    | with `public int $cacheTtl = ...;`.
    |
    */

    'cache' => [
        'enabled' => env( 'ARTISANPACK_AI_CACHE_ENABLED', false ),
        'ttl'     => (int) env( 'ARTISANPACK_AI_CACHE_TTL', 2592000 ),
        'store'   => env( 'ARTISANPACK_AI_CACHE_STORE' ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Usage Tracking
    |--------------------------------------------------------------------------
    |
    | The package writes one `ai_usage_events` row per agent run when
    | `enabled` is true. `retention_days` controls how long rows survive
    | before the `PurgeUsageEventsJob` removes them (0 disables purging).
    |
    */

    'usage' => [
        'enabled'        => env( 'ARTISANPACK_AI_USAGE_ENABLED', true ),
        'retention_days' => (int) env( 'ARTISANPACK_AI_USAGE_RETENTION_DAYS', 90 ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Budget Warnings
    |--------------------------------------------------------------------------
    |
    | Soft warning at `warning_percentage`% of the user-configured monthly
    | cap. The cap itself lives in the `settings` table under
    | `ai.monthly_budget_usd` and is nullable — no cap means no warning.
    | Recipients receive the `BudgetWarningMail` at most once per calendar
    | month.
    |
    */

    'budget' => [
        'warning_percentage' => (float) env( 'ARTISANPACK_AI_BUDGET_WARNING_PERCENTAGE', 80 ),
        'monthly_usd'        => env( 'ARTISANPACK_AI_BUDGET_MONTHLY_USD' ),
        'recipients'         => array_values( array_filter( array_map(
            'trim',
            explode( ',', (string) env( 'ARTISANPACK_AI_BUDGET_RECIPIENTS', '' ) ),
        ) ) ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Pricing (per 1K tokens)
    |--------------------------------------------------------------------------
    |
    | Rate table used by `CostEstimator` to attach `estimated_cost_usd` to
    | each usage row. Overridable via the published config. Unknown
    | provider/model combinations resolve to $0 — safe fallback, but you
    | won't see cost data for them until you add an entry.
    |
    */

    'pricing' => [

        'anthropic' => [
            'claude-3-5-haiku'  => [ 'input_per_1k' => 0.0008, 'output_per_1k' => 0.004 ],
            'claude-3-5-sonnet' => [ 'input_per_1k' => 0.003,  'output_per_1k' => 0.015 ],
            'claude-3-opus'     => [ 'input_per_1k' => 0.015,  'output_per_1k' => 0.075 ],
            'haiku'             => [ 'input_per_1k' => 0.0008, 'output_per_1k' => 0.004 ],
            'sonnet'            => [ 'input_per_1k' => 0.003,  'output_per_1k' => 0.015 ],
            'opus'              => [ 'input_per_1k' => 0.015,  'output_per_1k' => 0.075 ],
        ],

        'openai' => [
            'gpt-4o'      => [ 'input_per_1k' => 0.0025, 'output_per_1k' => 0.01 ],
            'gpt-4o-mini' => [ 'input_per_1k' => 0.00015, 'output_per_1k' => 0.0006 ],
        ],

        // Ollama models run locally; there's no metered cost. We keep the
        // rows here so the estimator's per-model $0 fallback isn't
        // misinterpreted as "we forgot to price this model" — a zero here
        // is deliberate.
        'ollama' => [
            'llama3.2:1b'  => [ 'input_per_1k' => 0.0, 'output_per_1k' => 0.0 ],
            'llama3.2:3b'  => [ 'input_per_1k' => 0.0, 'output_per_1k' => 0.0 ],
            'llama3.1:8b'  => [ 'input_per_1k' => 0.0, 'output_per_1k' => 0.0 ],
            'llama3.1:70b' => [ 'input_per_1k' => 0.0, 'output_per_1k' => 0.0 ],
            'qwen2.5:7b'   => [ 'input_per_1k' => 0.0, 'output_per_1k' => 0.0 ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Providers & Credentials
    |--------------------------------------------------------------------------
    |
    | Named provider configurations. Each entry holds the credentials and
    | connection defaults for a given AI service. Additional keys (base URL,
    | organization, project, etc.) can be added per provider as the
    | foundation matures.
    |
    */

    'providers' => [

        'anthropic' => [
            'driver'  => 'anthropic',
            'api_key' => env( 'ANTHROPIC_API_KEY' ),
            'model'   => env( 'ANTHROPIC_MODEL' ),
        ],

        'openai' => [
            'driver'  => 'openai',
            'api_key' => env( 'OPENAI_API_KEY' ),
            'model'   => env( 'OPENAI_MODEL' ),
        ],

        // Ollama is first-class in v1.0.0. It runs locally, so the API key
        // is optional (empty is fine) and the base URL doubles as the
        // "connection string." Defaults match Ollama's own install:
        // `ollama serve` on http://127.0.0.1:11434.
        'ollama' => [
            'driver'   => 'ollama',
            'api_key'  => env( 'OLLAMA_API_KEY', '' ),
            'base_url' => env( 'OLLAMA_BASE_URL', 'http://127.0.0.1:11434' ),
            'model'    => env( 'OLLAMA_MODEL', 'llama3.2:3b' ),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Features
    |--------------------------------------------------------------------------
    |
    | Feature flag stubs for AI-powered capabilities that downstream packages
    | can opt into. Each feature will be fleshed out with its own options as
    | the RFC is implemented.
    |
    */

    'features' => [

        'completions' => [
            'enabled' => env( 'ARTISANPACK_AI_COMPLETIONS_ENABLED', true ),
        ],

        'embeddings' => [
            'enabled' => env( 'ARTISANPACK_AI_EMBEDDINGS_ENABLED', false ),
        ],

        'tools' => [
            'enabled' => env( 'ARTISANPACK_AI_TOOLS_ENABLED', false ),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | JSON API
    |--------------------------------------------------------------------------
    |
    | REST/JSON endpoints backing the React and Vue admin surfaces. Disabled
    | routes leave the surface Livewire-only. `middleware` is applied in
    | order after Laravel's built-in `api` group is prepended, so the default
    | stack authenticates via Sanctum and then runs the ability gate below.
    | `ability` is checked against Laravel's Gate — undefined abilities deny
    | by default, so downstream apps must register the ability (cms-framework
    | does this automatically) before the endpoints become reachable.
    |
    */

    'api' => [

        'enabled'    => env( 'ARTISANPACK_AI_API_ENABLED', true ),
        'prefix'     => env( 'ARTISANPACK_AI_API_PREFIX', 'api/artisanpack-ai' ),
        'middleware' => [ 'api', 'auth:sanctum' ],
        'ability'    => 'manage_ai_settings',

    ],

];
