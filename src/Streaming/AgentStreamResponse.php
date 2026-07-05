<?php

/**
 * Server-sent-event response for an agent stream.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ai\Streaming;

use ArtisanPackUI\Ai\Agents\ArtisanPackAgent;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Wraps an `ArtisanPackAgent` in an SSE `text/event-stream` response for
 * progressive rendering in an admin dashboard.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */
final class AgentStreamResponse
{
    /**
     * Build a `StreamedResponse` for the given agent. The agent must be
     * configured with an input via `for()`; streaming is enabled here.
     *
     * @since 1.0.0
     *
     * @param  ArtisanPackAgent  $agent  Agent to run.
     *
     * @return StreamedResponse
     */
    public static function forAgent( ArtisanPackAgent $agent ): StreamedResponse
    {
        // Preserve any caller-supplied callback (metrics, audit log, WS
        // bridge) by chaining it after the SSE write. Silently overwriting
        // it would break wiring the caller wired up on purpose.
        $existing = $agent->streamCallback();

        return new StreamedResponse( function () use ( $agent, $existing ): void {
            $agent->streamTo( function ( string $chunk, string $accumulated ) use ( $existing ): void {
                echo 'data: ' . json_encode( [ 'chunk' => $chunk ] ) . "\n\n";
                self::flushBuffer();

                if ( null !== $existing ) {
                    $existing( $chunk, $accumulated );
                }
            } );

            $result = $agent->run();

            echo 'event: complete' . "\n";
            echo 'data: ' . json_encode( [ 'output' => $result ] ) . "\n\n";
            self::flushBuffer();
        }, 200, [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache, no-store, must-revalidate',
            'X-Accel-Buffering' => 'no',
        ] );
    }

    /**
     * Flush any active output buffer up to the SAPI. Guards on
     * `ob_get_level()` so we don't fire an E_NOTICE for every chunk when
     * no buffer is active (PHP-FPM with `output_buffering=Off`).
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected static function flushBuffer(): void
    {
        if ( ob_get_level() > 0 ) {
            ob_flush();
        }

        flush();
    }
}
