<?php

/**
 * Agent prompter contract.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ai\Contracts;

use ArtisanPackUI\Ai\Credentials\Credentials;

/**
 * Narrow seam concrete agents call from `execute()` to send a prompt to the
 * underlying model provider. Kept as a contract so tests can bind a fake
 * implementation and the shipped agents don't have to duplicate laravel/ai
 * wiring per agent class.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */
interface AgentPrompter
{
    /**
     * Send a text/multimodal prompt and return the raw JSON output plus
     * token telemetry.
     *
     * `$message` is a laravel/ai-style structured message body containing
     * either a plain string or an array of typed parts
     * (e.g. `[ ['type' => 'text', 'text' => '...'], ['type' => 'image_url', 'image_url' => '...'] ]`).
     *
     * Implementations must return an array with the keys:
     *   - `output`        : `array<string, mixed>`  — parsed JSON matching the agent's `outputSchema()`
     *   - `input_tokens`  : `int`
     *   - `output_tokens` : `int`
     *
     * Implementations SHOULD raise a
     * {@see \ArtisanPackUI\Ai\Exceptions\FeatureError} when the model
     * returns unparseable output, so concrete agents can propagate a
     * consistent error surface without re-implementing JSON validation.
     *
     * @since 1.0.0
     *
     * @param  Credentials         $credentials   Resolved credentials.
     * @param  string              $model         Resolved model identifier.
     * @param  string              $instructions  Resolved system prompt.
     * @param  array<int, array<string, mixed>>|string  $message  User message payload.
     * @param  array<string, mixed>  $outputSchema  Structured output schema to enforce.
     *
     * @return array{ output: array<string, mixed>, input_tokens: int, output_tokens: int }
     */
    public function prompt(
        Credentials $credentials,
        string $model,
        string $instructions,
        string|array $message,
        array $outputSchema,
    ): array;
}
