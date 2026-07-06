<?php

/**
 * Fake agent used to exercise the ArtisanPackAgent base class.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace Tests\Support;

use ArtisanPackUI\Ai\Agents\ArtisanPackAgent;
use ArtisanPackUI\Ai\Credentials\Credentials;

/**
 * Minimal subclass covering only `instructions()` and `outputSchema()`.
 *
 * `execute()` returns a canned response so the base pipeline (feature
 * gate, credential resolution, cache, telemetry) can be tested without a
 * real provider.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */
class FakeAgent extends ArtisanPackAgent
{
    /**
     * Feature key for this fake agent.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public string $featureKey = 'fake.echo';

    /**
     * Owning package for this fake agent.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public string $package = 'artisanpack-ui/ai-fake';

    /**
     * Default model for this fake agent.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public string $defaultModel = 'haiku';

    /**
     * Number of times `execute()` was called for the current instance.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public int $executeCallCount = 0;

    /**
     * Track the most-recent resolved instructions the pipeline handed us.
     *
     * @since 1.0.0
     *
     * @var string|null
     */
    public ?string $lastInstructions = null;

    /**
     * {@inheritDoc}
     */
    public function instructions(): string
    {
        return 'Echo the input.';
    }

    /**
     * {@inheritDoc}
     */
    public function outputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'echo' => [ 'type' => 'string' ],
            ],
            'required'   => [ 'echo' ],
        ];
    }

    /**
     * Return a canned response.
     *
     * @since 1.0.0
     *
     * @param  Credentials  $credentials   Resolved credentials.
     * @param  string       $model         Resolved model identifier.
     * @param  string       $instructions  Resolved system prompt.
     *
     * @return array{ output: array<string, mixed>, input_tokens: int, output_tokens: int }
     */
    protected function execute( Credentials $credentials, string $model, string $instructions ): array
    {
        $this->executeCallCount++;
        $this->lastInstructions = $instructions;

        $input = $this->input();
        $echo  = is_scalar( $input ) ? (string) $input : json_encode( $input );

        return [
            'output'        => [ 'echo' => (string) $echo ],
            'input_tokens'  => 42,
            'output_tokens' => 7,
        ];
    }
}
