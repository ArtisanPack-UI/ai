<?php

/**
 * Content rewrite agent.
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
 * General-purpose content rewriting agent — "make this shorter", "more
 * formal", "reading level 6". Consumed by both `visual-editor` and
 * `cms-framework`.
 *
 * ## Input
 *
 * ```
 * [
 *   'content'     => string,        // required, non-empty
 *   'intent'      => string,        // required, human-readable request
 *   'constraints' => string[]       // optional, extra rules for the model
 * ]
 * ```
 *
 * ## Output schema
 *
 * ```
 * {
 *   rewrite:       string  // rewritten content, or the original text unchanged
 *   changed_ratio: float   // 0.0 (identical) - 1.0 (fully rewritten)
 *   rationale:     string  // one-line explanation
 * }
 * ```
 *
 * The prompt explicitly instructs the model to return the original text
 * unchanged when the intent doesn't apply so consumers can trust
 * `changed_ratio` and `rewrite === content` as a signal that no false
 * rewrite happened.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */
class ContentRewriteAgent extends ArtisanPackAgent
{
    /**
     * {@inheritDoc}
     */
    public string $featureKey = 'ai.content_rewrite';

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
You rewrite user-supplied content according to a stated intent (e.g. "make this shorter", "more formal tone", "reading level 6").

Requirements:
- Preserve the original formatting exactly: if the input is Markdown, return Markdown; if it is HTML, return HTML with the same tag structure; if it is plain text, return plain text. Do NOT switch formats.
- Preserve links, code blocks, inline code, and image references verbatim unless the intent explicitly requests changing them.
- If the intent does not apply to the given content (e.g. asked to "make shorter" but the content is already a single sentence, or asked to shift tone that is already correct), return the input unchanged with a rationale explaining why no rewrite was needed.
- `changed_ratio` estimates how much content changed, from 0.0 (identical output) to 1.0 (fully rewritten).
- `rationale` is a single sentence describing what you changed or why you chose not to change anything.

Return a JSON object with keys: rewrite (string), changed_ratio (float 0..1), rationale (string).
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
            'required'             => [ 'rewrite', 'changed_ratio', 'rationale' ],
            'properties'           => [
                'rewrite'       => [ 'type' => 'string' ],
                'changed_ratio' => [
                    'type'    => 'number',
                    'minimum' => 0,
                    'maximum' => 1,
                ],
                'rationale'     => [ 'type' => 'string' ],
            ],
        ];
    }

    /**
     * {@inheritDoc}
     */
    protected function execute( Credentials $credentials, string $model, string $instructions ): array
    {
        $normalized = $this->normalizeInput( $this->input() );

        $prompter = app( AgentPrompter::class );

        $result = $prompter->prompt(
            credentials: $credentials,
            model: $model,
            instructions: $instructions,
            message: $this->buildMessage( $normalized ),
            outputSchema: $this->outputSchema(),
        );

        return [
            'output'        => $this->validateOutput( $result['output'], $normalized['content'] ),
            'input_tokens'  => (int) ( $result['input_tokens'] ?? 0 ),
            'output_tokens' => (int) ( $result['output_tokens'] ?? 0 ),
        ];
    }

    /**
     * Validate and shape-check the raw agent input.
     *
     * @since 1.0.0
     *
     * @param  mixed  $input  Raw agent input.
     *
     * @return array{ content: string, intent: string, constraints: array<int, string> }
     */
    protected function normalizeInput( mixed $input ): array
    {
        if ( ! is_array( $input ) ) {
            throw FeatureError::forFeature(
                $this->featureKey,
                'input must be an array with `content` and `intent` keys.',
            );
        }

        $content = isset( $input['content'] ) && is_string( $input['content'] ) ? $input['content'] : '';
        $intent  = isset( $input['intent'] ) && is_string( $input['intent'] ) ? trim( $input['intent'] ) : '';

        if ( '' === $content ) {
            throw FeatureError::forFeature( $this->featureKey, '`content` must be a non-empty string.' );
        }

        if ( '' === $intent ) {
            throw FeatureError::forFeature( $this->featureKey, '`intent` must be a non-empty string.' );
        }

        $constraints = [];

        if ( isset( $input['constraints'] ) && is_array( $input['constraints'] ) ) {
            foreach ( $input['constraints'] as $constraint ) {
                if ( is_string( $constraint ) && '' !== trim( $constraint ) ) {
                    $constraints[] = trim( $constraint );
                }
            }
        }

        return [
            'content'     => $content,
            'intent'      => $intent,
            'constraints' => $constraints,
        ];
    }

    /**
     * Assemble the structured message body for the prompter.
     *
     * @since 1.0.0
     *
     * @param  array{ content: string, intent: string, constraints: array<int, string> }  $normalized  Normalized input.
     *
     * @return array<int, array<string, string>>
     */
    protected function buildMessage( array $normalized ): array
    {
        $parts = [
            [ 'type' => 'text', 'text' => sprintf( 'Intent: %s', $normalized['intent'] ) ],
        ];

        if ( [] !== $normalized['constraints'] ) {
            $parts[] = [
                'type' => 'text',
                'text' => 'Constraints:' . "\n- " . implode( "\n- ", $normalized['constraints'] ),
            ];
        }

        $parts[] = [
            'type' => 'text',
            'text' => "Content:\n" . $normalized['content'],
        ];

        return $parts;
    }

    /**
     * Enforce output invariants and compute a canonical `changed_ratio`.
     *
     * The `changed_ratio` claimed by the model is sanity-checked: if the
     * model says "0.0" but the rewrite differs from the original (or the
     * reverse), we recompute using a simple similarity heuristic rather
     * than trust a hallucinated number.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $output   Decoded model output.
     * @param  string                $original Original content the model was asked to rewrite.
     *
     * @return array{ rewrite: string, changed_ratio: float, rationale: string }
     */
    protected function validateOutput( array $output, string $original ): array
    {
        $rewrite   = isset( $output['rewrite'] ) ? (string) $output['rewrite'] : '';
        $rationale = isset( $output['rationale'] ) ? (string) $output['rationale'] : '';
        $claimed   = isset( $output['changed_ratio'] ) ? (float) $output['changed_ratio'] : 0.0;

        if ( '' === $rewrite ) {
            $rewrite = $original;
        }

        $identical    = $rewrite === $original;
        $recomputed   = $identical ? 0.0 : $this->similarityGap( $original, $rewrite );
        $changedRatio = $identical ? 0.0 : max( $recomputed, min( 1.0, max( 0.0, $claimed ) ) );

        return [
            'rewrite'       => $rewrite,
            'changed_ratio' => $changedRatio,
            'rationale'     => $rationale,
        ];
    }

    /**
     * Rough dissimilarity between two strings, `0.0` (identical) to `1.0`
     * (no shared content).
     *
     * Uses `similar_text()` — cheap, deterministic, and good enough for a
     * sanity-check floor on the model's self-reported `changed_ratio`.
     *
     * @since 1.0.0
     *
     * @param  string  $a  First string.
     * @param  string  $b  Second string.
     *
     * @return float
     */
    protected function similarityGap( string $a, string $b ): float
    {
        if ( '' === $a && '' === $b ) {
            return 0.0;
        }

        // `similar_text()` is O(n³) — a 50KB post can blow past the request
        // timeout. Cap each side at 4KB for the sanity-check floor; the
        // recompute doesn't need whole-document precision.
        $capped_a = strlen( $a ) > 4096 ? substr( $a, 0, 4096 ) : $a;
        $capped_b = strlen( $b ) > 4096 ? substr( $b, 0, 4096 ) : $b;

        similar_text( $capped_a, $capped_b, $percent );

        return max( 0.0, min( 1.0, 1.0 - ( $percent / 100.0 ) ) );
    }
}
