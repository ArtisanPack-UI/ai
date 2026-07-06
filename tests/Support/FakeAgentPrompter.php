<?php

/**
 * Fake AgentPrompter used to exercise concrete agent execute() paths.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace Tests\Support;

use ArtisanPackUI\Ai\Contracts\AgentPrompter;
use ArtisanPackUI\Ai\Credentials\Credentials;

/**
 * Records every call and returns a canned response. Tests configure the
 * next response with `queue()`.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */
class FakeAgentPrompter implements AgentPrompter
{
    /**
     * Recorded invocations, most-recent-first.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $calls = [];

    /**
     * FIFO queue of canned responses.
     *
     * @var array<int, array<string, mixed>>
     */
    protected array $queue = [];

    /**
     * Queue a canned response. Missing token counts default to 0.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $output         Parsed model output.
     * @param  int                    $inputTokens   Reported input tokens.
     * @param  int                    $outputTokens  Reported output tokens.
     *
     * @return void
     */
    public function queue( array $output, int $inputTokens = 100, int $outputTokens = 50 ): void
    {
        $this->queue[] = [
            'output'        => $output,
            'input_tokens'  => $inputTokens,
            'output_tokens' => $outputTokens,
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function prompt(
        Credentials $credentials,
        string $model,
        string $instructions,
        string|array $message,
        array $outputSchema,
    ): array {
        $this->calls[] = [
            'credentials'   => $credentials,
            'model'         => $model,
            'instructions'  => $instructions,
            'message'       => $message,
            'output_schema' => $outputSchema,
        ];

        if ( [] === $this->queue ) {
            return [
                'output'        => [],
                'input_tokens'  => 0,
                'output_tokens' => 0,
            ];
        }

        return array_shift( $this->queue );
    }
}
