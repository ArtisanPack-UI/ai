<?php

/**
 * Fake streaming agent used to exercise the streaming pipeline.
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
 * Splits the input string into chunks and emits them through the
 * registered stream callback before returning the assembled result.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */
class FakeStreamingAgent extends ArtisanPackAgent
{
    /**
     * Feature key.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public string $featureKey = 'fake.stream';

    /**
     * Owning package.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public string $package = 'artisanpack-ui/ai-fake';

    /**
     * Default model.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public string $defaultModel = 'haiku';

    /**
     * RFC-mandated: streaming is default-on for long-running agents.
     *
     * @since 1.0.0
     *
     * @var bool
     */
    public bool $stream = true;

    /**
     * {@inheritDoc}
     */
    public function instructions(): string
    {
        return 'Stream the input.';
    }

    /**
     * {@inheritDoc}
     */
    public function outputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [ 'text' => [ 'type' => 'string' ] ],
            'required'   => [ 'text' ],
        ];
    }

    /**
     * Emit each character of the input as a chunk, then return the whole.
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
        $input       = (string) $this->input();
        $accumulated = '';

        if ( $this->isStreaming() ) {
            foreach ( str_split( $input ) as $chunk ) {
                $accumulated .= $chunk;
                $this->emitChunk( $chunk, $accumulated );
            }
        } else {
            $accumulated = $input;
        }

        return [
            'output'        => [ 'text' => $accumulated ],
            'input_tokens'  => 10,
            'output_tokens' => strlen( $input ),
        ];
    }
}
