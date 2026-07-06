<?php

/**
 * Alt-text generation agent.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ai\Agents;

use ArtisanPackUI\Ai\Contracts\AgentPrompter;
use ArtisanPackUI\Ai\Credentials\Credentials;
use ArtisanPackUI\Ai\Exceptions\FeatureError;

/**
 * Cross-cutting vision agent that produces accessibility-friendly alt
 * text for an image. Consumed by `media-library` (on upload) and by
 * `visual-editor` (on drop-into-content) so both packages share one
 * definition + prompt + feature toggle.
 *
 * ## Input
 *
 * The agent's input may be any of:
 *   - a local filesystem path to an image
 *   - a fully-qualified `http(s)://` URL
 *   - a base64-encoded string (bare, or a `data:image/...` URI)
 *   - an array `[ 'source' => 'path|url|base64', 'value' => string ]`
 *     for callers that want to be explicit
 *
 * Bytes are NEVER read off disk here — the agent forwards the reference to
 * the model provider as-is. Providers that need file bytes (Anthropic,
 * OpenAI) are expected to fetch/upload them, matching the laravel/ai
 * attachment contract.
 *
 * ## Output schema
 *
 * ```
 * {
 *   alt_text:   string  // <=150 chars, no trailing period
 *   confidence: float   // 0.0 - 1.0
 *   warnings:   string[] // e.g. "image appears to be a screenshot of text"
 * }
 * ```
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */
class AltTextGenerationAgent extends ArtisanPackAgent
{
    /**
     * {@inheritDoc}
     */
    public string $featureKey = 'ai.alt_text';

    /**
     * {@inheritDoc}
     */
    public string $package = 'artisanpack-ui/ai';

    /**
     * {@inheritDoc}
     */
    public string $defaultModel = 'claude-haiku-4-5';

    /**
     * {@inheritDoc}
     */
    public function instructions(): string
    {
        return <<<'PROMPT'
You generate concise, accessibility-friendly alt text for the supplied image.

Requirements:
- Describe the image's meaningful content in <= 150 characters.
- Prefer active voice; do not start with "Image of" or "Picture of".
- Do not include a trailing period.
- If the image is decorative (pure ornament, no informational content), return an empty string for alt_text and add a warning "decorative image — consider empty alt attribute".
- If the image is unreadable, corrupt, or clearly not an image, add a warning describing what you see.
- If the image is a screenshot of text, transcribe the visible text and add a warning "screenshot of text — provide the transcription in body content".

Return a JSON object with keys: alt_text (string), confidence (float 0..1), warnings (array of strings).
PROMPT;
    }

    /**
     * {@inheritDoc}
     */
    public function outputSchema(): array
    {
        return [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => [ 'alt_text', 'confidence', 'warnings' ],
            'properties'           => [
                'alt_text'   => [
                    'type'      => 'string',
                    'maxLength' => 150,
                ],
                'confidence' => [
                    'type'    => 'number',
                    'minimum' => 0,
                    'maximum' => 1,
                ],
                'warnings'   => [
                    'type'  => 'array',
                    'items' => [ 'type' => 'string' ],
                ],
            ],
        ];
    }

    /**
     * {@inheritDoc}
     */
    protected function execute( Credentials $credentials, string $model, string $instructions ): array
    {
        $reference = $this->normalizeImageReference( $this->input() );

        $prompter = app( AgentPrompter::class );

        $result = $prompter->prompt(
            credentials: $credentials,
            model: $model,
            instructions: $instructions,
            message: [
                [ 'type' => 'text', 'text' => 'Generate alt text for the attached image.' ],
                $reference,
            ],
            outputSchema: $this->outputSchema(),
        );

        return [
            'output'        => $this->validateOutput( $result['output'] ),
            'input_tokens'  => (int) ( $result['input_tokens'] ?? 0 ),
            'output_tokens' => (int) ( $result['output_tokens'] ?? 0 ),
        ];
    }

    /**
     * Coerce the raw agent input into a laravel/ai-style image attachment.
     *
     * Rejects inputs that clearly aren't an image reference before we
     * spend tokens on them.
     *
     * @since 1.0.0
     *
     * @param  mixed  $input  Agent input payload.
     *
     * @return array{ type: string, source: string, value: string }
     */
    protected function normalizeImageReference( mixed $input ): array
    {
        if ( is_array( $input ) && isset( $input['source'], $input['value'] ) ) {
            $source = (string) $input['source'];
            $value  = (string) $input['value'];
        } elseif ( is_string( $input ) && '' !== $input ) {
            $source = $this->detectSource( $input );
            $value  = $input;
        } else {
            throw FeatureError::forFeature(
                $this->featureKey,
                'input must be an image path, URL, base64 string, or [source, value] pair.',
            );
        }

        if ( ! in_array( $source, [ 'path', 'url', 'base64' ], true ) ) {
            throw FeatureError::forFeature(
                $this->featureKey,
                sprintf( 'unsupported image source "%s"', $source ),
            );
        }

        if ( 'path' === $source && ! is_readable( $value ) ) {
            throw FeatureError::forFeature(
                $this->featureKey,
                sprintf( 'image path "%s" is not readable', $value ),
            );
        }

        return [
            'type'   => 'image',
            'source' => $source,
            'value'  => $value,
        ];
    }

    /**
     * Guess whether a bare string is a URL, filesystem path, or base64 blob.
     *
     * @since 1.0.0
     *
     * @param  string  $input  Raw input string.
     *
     * @return string
     */
    protected function detectSource( string $input ): string
    {
        if ( str_starts_with( $input, 'http://' ) || str_starts_with( $input, 'https://' ) ) {
            return 'url';
        }

        if ( str_starts_with( $input, 'data:image/' ) ) {
            return 'base64';
        }

        // A bare base64 blob typically has no path separator + only base64
        // chars. Require at least 40 characters and a length that's a
        // multiple of 4 (base64 padding) — otherwise a short unqualified
        // filename like `favicon` gets misclassified as base64 and shipped
        // to the model as image data.
        if (
            strlen( $input ) >= 40
            && 0 === strlen( $input ) % 4
            && ! str_contains( $input, '/' )
            && ! str_contains( $input, '\\' )
            && 1 === preg_match( '#^[A-Za-z0-9+/=]+$#', $input )
        ) {
            return 'base64';
        }

        return 'path';
    }

    /**
     * Enforce the output schema shape before returning to callers.
     *
     * The prompter validates JSON parseability; this method enforces the
     * agent-specific invariants (key presence, length cap, confidence
     * range).
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $output  Decoded model output.
     *
     * @return array{ alt_text: string, confidence: float, warnings: array<int, string> }
     */
    protected function validateOutput( array $output ): array
    {
        $altText    = isset( $output['alt_text'] ) ? (string) $output['alt_text'] : '';
        $confidence = isset( $output['confidence'] ) ? (float) $output['confidence'] : 0.0;
        $warnings   = [];

        if ( isset( $output['warnings'] ) && is_array( $output['warnings'] ) ) {
            foreach ( $output['warnings'] as $warning ) {
                if ( is_string( $warning ) && '' !== $warning ) {
                    $warnings[] = $warning;
                }
            }
        }

        if ( mb_strlen( $altText ) > 150 ) {
            $altText = mb_substr( $altText, 0, 150 );
        }

        if ( $confidence < 0.0 ) {
            $confidence = 0.0;
        } elseif ( $confidence > 1.0 ) {
            $confidence = 1.0;
        }

        return [
            'alt_text'   => $altText,
            'confidence' => $confidence,
            'warnings'   => $warnings,
        ];
    }
}
