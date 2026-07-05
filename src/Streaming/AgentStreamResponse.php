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
        return new StreamedResponse( function () use ( $agent ): void {
            $agent->streamTo( function ( string $chunk ): void {
                echo 'data: ' . json_encode( [ 'chunk' => $chunk ] ) . "\n\n";

                if ( function_exists( 'ob_flush' ) ) {
                    @ob_flush();
                }

                flush();
            } );

            $result = $agent->run();

            echo 'event: complete' . "\n";
            echo 'data: ' . json_encode( [ 'output' => $result ] ) . "\n\n";

            if ( function_exists( 'ob_flush' ) ) {
                @ob_flush();
            }

            flush();
        }, 200, [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache, no-store, must-revalidate',
            'X-Accel-Buffering' => 'no',
        ] );
    }
}
