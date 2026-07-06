<?php

/**
 * Generic summarization agent.
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
use JsonException;

/**
 * Generic summarization agent, designed to be subclassed by digest
 * features in other packages (analytics, security-analytics, reporting)
 * rather than reimplemented per surface.
 *
 * ## Input
 *
 * ```
 * [
 *   'items'  => array,        // required, list of things to summarize
 *   'focus'  => string,       // optional, focus lens (e.g. "user impact")
 *   'length' => 'brief'|'detailed'  // optional, defaults to 'brief'
 * ]
 * ```
 *
 * `items` is intentionally loose — the model receives its JSON-encoded
 * form, so callers can pass an array of log lines, an array of associative
 * arrays, an array of Eloquent Arrayable objects, etc. Subclasses that
 * need to pre-shape items should override {@see normalizeItems()}.
 *
 * ## Output schema
 *
 * ```
 * {
 *   summary:    string    // top-line narrative
 *   key_points: string[]  // bulletable highlights
 *   caveats:    string[]  // gotchas, uncertainties, gaps in the data
 * }
 * ```
 *
 * ## Subclassing
 *
 * Analytics/security-analytics digests should extend this class rather
 * than reimplement: override `$featureKey`, `$package`, and optionally
 * {@see instructions()} to bias the prompt for their domain. The rest of
 * the pipeline (input validation, empty-input short-circuit, prompter
 * dispatch, output validation) is inherited.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */
class SummarizationAgent extends ArtisanPackAgent
{
    /**
     * {@inheritDoc}
     */
    public string $featureKey = 'ai.summarize';

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
You summarize a list of items into a short narrative plus bulletable key points.

Requirements:
- Base every claim in the summary strictly on the provided items. Do NOT invent facts, dates, names, or numbers.
- If `focus` is provided, bias `summary` and `key_points` toward that lens without dropping obviously important non-focus items — mention them as caveats instead.
- If `length` is `brief`, keep `summary` to 1-2 sentences and `key_points` to at most 3 entries. If `length` is `detailed`, allow up to 5 sentences in `summary` and up to 7 `key_points`.
- Include `caveats` for missing context, contradictory items, ambiguous data, or anything a downstream reader should know about the confidence of the summary. An empty array is fine when the input is clean.

Return a JSON object with keys: summary (string), key_points (array of strings), caveats (array of strings).
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
            'required'             => [ 'summary', 'key_points', 'caveats' ],
            'properties'           => [
                'summary'    => [ 'type' => 'string' ],
                'key_points' => [
                    'type'  => 'array',
                    'items' => [ 'type' => 'string' ],
                ],
                'caveats'    => [
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
        $normalized = $this->normalizeInput( $this->input() );

        // Empty items => short-circuit to a static, honest response. No
        // point burning tokens (or letting a model hallucinate) when the
        // caller passed nothing to summarize.
        if ( [] === $normalized['items'] ) {
            return [
                'output'        => [
                    'summary'    => 'No items to summarize.',
                    'key_points' => [],
                    'caveats'    => [ 'input list was empty' ],
                ],
                'input_tokens'  => 0,
                'output_tokens' => 0,
            ];
        }

        $prompter = app( AgentPrompter::class );

        $result = $prompter->prompt(
            credentials: $credentials,
            model: $model,
            instructions: $instructions,
            message: $this->buildMessage( $normalized ),
            outputSchema: $this->outputSchema(),
        );

        return [
            'output'        => $this->validateOutput( $result['output'], $normalized['length'] ),
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
     * @return array{ items: array<int, mixed>, focus: string|null, length: string }
     */
    protected function normalizeInput( mixed $input ): array
    {
        if ( ! is_array( $input ) ) {
            throw FeatureError::forFeature(
                $this->featureKey,
                'input must be an array with an `items` key.',
            );
        }

        if ( ! isset( $input['items'] ) || ! is_array( $input['items'] ) ) {
            throw FeatureError::forFeature( $this->featureKey, '`items` must be an array.' );
        }

        $items  = $this->normalizeItems( $input['items'] );
        $focus  = isset( $input['focus'] ) && is_string( $input['focus'] ) ? trim( $input['focus'] ) : '';
        $length = isset( $input['length'] ) && is_string( $input['length'] ) ? strtolower( $input['length'] ) : 'brief';

        if ( ! in_array( $length, [ 'brief', 'detailed' ], true ) ) {
            $length = 'brief';
        }

        return [
            'items'  => $items,
            'focus'  => '' === $focus ? null : $focus,
            'length' => $length,
        ];
    }

    /**
     * Hook subclasses use to pre-shape items before serialization.
     *
     * Default behaviour: pass items through unchanged. Digest-specific
     * subclasses can strip PII, coerce timestamps, or bucket events
     * without duplicating the surrounding validation logic.
     *
     * @since 1.0.0
     *
     * @param  array<int|string, mixed>  $items  Raw item list.
     *
     * @return array<int, mixed>
     */
    protected function normalizeItems( array $items ): array
    {
        return array_values( $items );
    }

    /**
     * Assemble the structured message body for the prompter.
     *
     * @since 1.0.0
     *
     * @param  array{ items: array<int, mixed>, focus: string|null, length: string }  $normalized  Normalized input.
     *
     * @return array<int, array<string, string>>
     */
    protected function buildMessage( array $normalized ): array
    {
        $parts = [
            [ 'type' => 'text', 'text' => sprintf( 'Length: %s', $normalized['length'] ) ],
        ];

        if ( null !== $normalized['focus'] ) {
            $parts[] = [ 'type' => 'text', 'text' => sprintf( 'Focus: %s', $normalized['focus'] ) ];
        }

        try {
            $encoded = json_encode(
                $normalized['items'],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
        } catch ( JsonException $exception ) {
            // Rejecting is safer than silently shipping `null` items to the
            // model — otherwise a caller who accidentally passes a resource
            // or closure inside `items` gets a hallucinated summary of
            // `null` entries.
            throw FeatureError::forFeature(
                $this->featureKey,
                sprintf( 'items could not be serialized for the model: %s', $exception->getMessage() ),
                $exception,
            );
        }

        $parts[] = [
            'type' => 'text',
            'text' => "Items:\n" . $encoded,
        ];

        return $parts;
    }

    /**
     * Enforce output invariants — key presence, list types, length caps.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $output  Decoded model output.
     * @param  string                $length  Resolved length hint (`brief` or `detailed`).
     *
     * @return array{ summary: string, key_points: array<int, string>, caveats: array<int, string> }
     */
    protected function validateOutput( array $output, string $length ): array
    {
        $summary   = isset( $output['summary'] ) ? (string) $output['summary'] : '';
        $keyPoints = $this->stringList( $output['key_points'] ?? [] );
        $caveats   = $this->stringList( $output['caveats'] ?? [] );

        // Clamp key-point counts to the requested length so callers can
        // trust the length hint even when the model overshoots.
        $maxKeyPoints = 'detailed' === $length ? 7 : 3;

        if ( count( $keyPoints ) > $maxKeyPoints ) {
            $keyPoints = array_slice( $keyPoints, 0, $maxKeyPoints );
        }

        return [
            'summary'    => $summary,
            'key_points' => $keyPoints,
            'caveats'    => $caveats,
        ];
    }

    /**
     * Filter a raw list into a clean array of non-empty strings.
     *
     * @since 1.0.0
     *
     * @param  mixed  $raw  Raw list from the model.
     *
     * @return array<int, string>
     */
    protected function stringList( mixed $raw ): array
    {
        if ( ! is_array( $raw ) ) {
            return [];
        }

        $out = [];

        foreach ( $raw as $value ) {
            if ( is_string( $value ) && '' !== trim( $value ) ) {
                $out[] = $value;
            }
        }

        return $out;
    }
}
