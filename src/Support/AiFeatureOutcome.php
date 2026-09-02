<?php

/**
 * Normalized outcome of running an AI feature behind the shared handler.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.2.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ai\Support;

/**
 * Immutable, transport-agnostic result of one AI agent invocation.
 *
 * {@see \ArtisanPackUI\Ai\Concerns\HandlesAiFeatureResponses::handleAiFeature()}
 * runs an agent callable and folds every result — success or any of the five
 * failure layers — into one of these. It carries the normalized
 * `(status, errorCode, message)` tuple the issue calls for, plus the
 * feature key and, on success, the agent output.
 *
 * Envelope shape stays with the caller: a JSON controller reads
 * {@see $status}, {@see $errorCode}, and {@see $message}; a Livewire
 * component reads {@see $statusSlug} to name its browser event and
 * {@see $output} / {@see $message} for the payload. Neither the HTTP status
 * code nor the kebab-cased event slug is mechanically derivable from the
 * other, so both are held explicitly here — this object is the single place
 * the mapping lives.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.2.0
 */
final class AiFeatureOutcome
{
    /**
     * Build the outcome.
     *
     * @since 1.2.0
     *
     * @param  bool         $succeeded   Whether the agent call completed without throwing.
     * @param  string       $feature     Feature key the call ran under.
     * @param  string       $statusSlug  Transport status token (`success`, `disabled`, `missing-credentials`, `invalid-input`, `budget-exceeded`, `error`).
     * @param  int          $status      HTTP status code (200 on success).
     * @param  string|null  $errorCode   Machine error code, or null on success.
     * @param  string|null  $message     User-facing error message, or null on success.
     * @param  mixed        $output      Agent output on success, or null on failure.
     */
    private function __construct(
        public readonly bool $succeeded,
        public readonly string $feature,
        public readonly string $statusSlug,
        public readonly int $status,
        public readonly ?string $errorCode,
        public readonly ?string $message,
        public readonly mixed $output,
    ) {
    }

    /**
     * Build a successful outcome carrying the agent output.
     *
     * @since 1.2.0
     *
     * @param  string  $feature  Feature key the call ran under.
     * @param  mixed   $output   Shaped agent output.
     *
     * @return self
     */
    public static function success( string $feature, mixed $output ): self
    {
        return new self(
            succeeded: true,
            feature: $feature,
            statusSlug: 'success',
            status: 200,
            errorCode: null,
            message: null,
            output: $output,
        );
    }

    /**
     * Build a failed outcome from the normalized error tuple.
     *
     * @since 1.2.0
     *
     * @param  string  $feature     Feature key the call ran under.
     * @param  int     $status      HTTP status code (403, 503, 422, 429, 500).
     * @param  string  $errorCode   Machine error code (e.g. `feature_disabled`).
     * @param  string  $statusSlug  Transport status token (e.g. `disabled`).
     * @param  string  $message     User-facing error message.
     *
     * @return self
     */
    public static function failed(
        string $feature,
        int $status,
        string $errorCode,
        string $statusSlug,
        string $message,
    ): self {
        return new self(
            succeeded: false,
            feature: $feature,
            statusSlug: $statusSlug,
            status: $status,
            errorCode: $errorCode,
            message: $message,
            output: null,
        );
    }
}
