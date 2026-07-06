<?php

/**
 * Generic feature-level runtime error.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ai\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Thrown by concrete agents when domain input is unreadable, malformed, or
 * otherwise unusable — for example, when the AltText agent is handed a
 * file path that isn't a readable image.
 *
 * Kept separate from {@see FeatureDisabledException} and
 * {@see MissingCredentialsException} so callers can catch domain-level
 * problems distinctly from feature-flag or credential problems.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */
class FeatureError extends RuntimeException
{
    /**
     * Build the exception for a given feature key + reason.
     *
     * @since 1.0.0
     *
     * @param  string          $featureKey  Feature key raising the error.
     * @param  string          $reason      Human-readable explanation.
     * @param  Throwable|null  $previous    Optional cause.
     *
     * @return self
     */
    public static function forFeature( string $featureKey, string $reason, ?Throwable $previous = null ): self
    {
        return new self(
            sprintf( 'AI feature "%s" could not run: %s', $featureKey, $reason ),
            0,
            $previous,
        );
    }
}
