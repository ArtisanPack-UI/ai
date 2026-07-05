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
    | avoid redundant provider calls. When disabled the agent always calls
    | the provider.
    |
    */

    'cache' => [
        'enabled' => env( 'ARTISANPACK_AI_CACHE_ENABLED', false ),
        'ttl'     => (int) env( 'ARTISANPACK_AI_CACHE_TTL', 3600 ),
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

];
