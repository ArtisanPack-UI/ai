<?php

/**
 * AI usage JSON API controller.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ai\Http\Controllers\Api;

use ArtisanPackUI\Ai\Repositories\AiUsageRepository;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Aggregations over `ai_usage_events` for the admin dashboard.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */
class UsageController extends AbstractAdminController
{
    /**
     * GET /usage — totals, per-feature breakdown, and daily buckets.
     *
     * Query params:
     *   `from` (YYYY-MM-DD, inclusive) — defaults to the start of the
     *   current calendar month.
     *   `to`   (YYYY-MM-DD, inclusive) — defaults to the end of the
     *   current calendar month.
     *
     * Unparseable date strings 422 rather than silently falling back — the
     * Livewire dashboard tolerates partial input mid-typing, but callers
     * of a REST endpoint should hear about a malformed value.
     *
     * @since 1.0.0
     *
     * @param  Request            $request     Incoming HTTP request.
     * @param  AiUsageRepository  $repository  Usage repository.
     *
     * @return JsonResponse
     */
    public function index( Request $request, AiUsageRepository $repository ): JsonResponse
    {
        $this->authorizeAdmin();

        $now = Carbon::now();

        try {
            $from = $this->parseBound( $request->query( 'from' ), $now->copy()->startOfMonth()->toDateString(), false );
            $to   = $this->parseBound( $request->query( 'to' ), $now->copy()->endOfMonth()->toDateString(), true );
        } catch ( InvalidFormatException $exception ) {
            return new JsonResponse( [
                'message' => __( 'Validation failed.' ),
                'errors'  => [
                    'from' => [ (string) __( 'Dates must be ISO 8601 (YYYY-MM-DD).' ) ],
                ],
            ], 422 );
        }

        return new JsonResponse( [
            'range'      => [
                'from' => $from->toIso8601String(),
                'to'   => $to->toIso8601String(),
            ],
            'totals'     => $repository->totals( $from, $to ),
            'by_feature' => $repository->byFeature( $from, $to ),
            'daily'      => $repository->byPeriod( 'day', $from, $to ),
        ] );
    }

    /**
     * Parse a query-string bound, falling back to a default ISO date.
     *
     * @since 1.0.0
     *
     * @param  mixed   $raw       Raw query value.
     * @param  string  $default   Default ISO date string when raw is blank.
     * @param  bool    $endOfDay  Whether to snap to end-of-day (upper bound).
     *
     * @return Carbon
     */
    protected function parseBound( mixed $raw, string $default, bool $endOfDay ): Carbon
    {
        if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
            $raw = $default;
        }

        $parsed = Carbon::parse( (string) $raw );

        return $endOfDay ? $parsed->endOfDay() : $parsed->startOfDay();
    }
}
