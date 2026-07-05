<?php

/**
 * Repository for `ai_usage_events` aggregations.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ai\Repositories;

use DateTimeInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;

/**
 * Aggregation queries over `ai_usage_events` for the admin dashboard.
 *
 * All methods return plain arrays so callers stay decoupled from Eloquent
 * models. Aggregations are cheap enough on a single indexed table that no
 * caching layer is needed at this scope.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */
final class AiUsageRepository
{
    /**
     * Build the repository.
     *
     * @since 1.0.0
     *
     * @param  ConnectionResolverInterface  $db  Database connection resolver.
     */
    public function __construct( protected ConnectionResolverInterface $db )
    {
    }

    /**
     * Sum of input, output, cost, and event count for the given range.
     *
     * @since 1.0.0
     *
     * @param  DateTimeInterface|null  $from  Optional lower bound (inclusive).
     * @param  DateTimeInterface|null  $to    Optional upper bound (inclusive).
     *
     * @return array{ input_tokens: int, output_tokens: int, cost_usd: float, events: int }
     */
    public function totals( ?DateTimeInterface $from = null, ?DateTimeInterface $to = null ): array
    {
        $row = $this->range( $this->table(), $from, $to )
            ->selectRaw( 'COALESCE(SUM(input_tokens), 0) AS input_tokens' )
            ->selectRaw( 'COALESCE(SUM(output_tokens), 0) AS output_tokens' )
            ->selectRaw( 'COALESCE(SUM(estimated_cost_usd), 0) AS cost_usd' )
            ->selectRaw( 'COUNT(*) AS events' )
            ->first();

        return [
            'input_tokens'  => (int) ( $row->input_tokens ?? 0 ),
            'output_tokens' => (int) ( $row->output_tokens ?? 0 ),
            'cost_usd'      => (float) ( $row->cost_usd ?? 0 ),
            'events'        => (int) ( $row->events ?? 0 ),
        ];
    }

    /**
     * Month-to-date cost total in USD for the given month.
     *
     * @since 1.0.0
     *
     * @param  DateTimeInterface|null  $now  Reference "now" (defaults to current time).
     *
     * @return float
     */
    public function monthToDateCost( ?DateTimeInterface $now = null ): float
    {
        $now   = Carbon::instance( $now ? Carbon::instance( $now ) : Carbon::now() );
        $start = $now->copy()->startOfMonth();

        return (float) $this->range( $this->table(), $start, $now )
            ->sum( 'estimated_cost_usd' );
    }

    /**
     * Totals grouped by feature key.
     *
     * @since 1.0.0
     *
     * @param  DateTimeInterface|null  $from  Optional lower bound.
     * @param  DateTimeInterface|null  $to    Optional upper bound.
     *
     * @return list<array{ feature_key: string, input_tokens: int, output_tokens: int, cost_usd: float, events: int }>
     */
    public function byFeature( ?DateTimeInterface $from = null, ?DateTimeInterface $to = null ): array
    {
        return $this->range( $this->table(), $from, $to )
            ->select( 'feature_key' )
            ->selectRaw( 'COALESCE(SUM(input_tokens), 0) AS input_tokens' )
            ->selectRaw( 'COALESCE(SUM(output_tokens), 0) AS output_tokens' )
            ->selectRaw( 'COALESCE(SUM(estimated_cost_usd), 0) AS cost_usd' )
            ->selectRaw( 'COUNT(*) AS events' )
            ->groupBy( 'feature_key' )
            ->orderBy( 'feature_key' )
            ->get()
            ->map( fn ( $row ) => [
                'feature_key'   => (string) $row->feature_key,
                'input_tokens'  => (int) $row->input_tokens,
                'output_tokens' => (int) $row->output_tokens,
                'cost_usd'      => (float) $row->cost_usd,
                'events'        => (int) $row->events,
            ] )
            ->all();
    }

    /**
     * Totals grouped by package name.
     *
     * @since 1.0.0
     *
     * @param  DateTimeInterface|null  $from  Optional lower bound.
     * @param  DateTimeInterface|null  $to    Optional upper bound.
     *
     * @return list<array{ package: string, input_tokens: int, output_tokens: int, cost_usd: float, events: int }>
     */
    public function byPackage( ?DateTimeInterface $from = null, ?DateTimeInterface $to = null ): array
    {
        return $this->range( $this->table(), $from, $to )
            ->select( 'package' )
            ->selectRaw( 'COALESCE(SUM(input_tokens), 0) AS input_tokens' )
            ->selectRaw( 'COALESCE(SUM(output_tokens), 0) AS output_tokens' )
            ->selectRaw( 'COALESCE(SUM(estimated_cost_usd), 0) AS cost_usd' )
            ->selectRaw( 'COUNT(*) AS events' )
            ->groupBy( 'package' )
            ->orderBy( 'package' )
            ->get()
            ->map( fn ( $row ) => [
                'package'       => (string) $row->package,
                'input_tokens'  => (int) $row->input_tokens,
                'output_tokens' => (int) $row->output_tokens,
                'cost_usd'      => (float) $row->cost_usd,
                'events'        => (int) $row->events,
            ] )
            ->all();
    }

    /**
     * Totals bucketed by day / week / month.
     *
     * @since 1.0.0
     *
     * @param  'day'|'month'|'week'    $bucket  Bucket size.
     * @param  DateTimeInterface|null  $from    Optional lower bound.
     * @param  DateTimeInterface|null  $to      Optional upper bound.
     *
     * @return list<array{ bucket: string, input_tokens: int, output_tokens: int, cost_usd: float, events: int }>
     */
    public function byPeriod( string $bucket, ?DateTimeInterface $from = null, ?DateTimeInterface $to = null ): array
    {
        $expression = $this->bucketExpression( $bucket );

        return $this->range( $this->table(), $from, $to )
            ->selectRaw( $expression . ' AS bucket' )
            ->selectRaw( 'COALESCE(SUM(input_tokens), 0) AS input_tokens' )
            ->selectRaw( 'COALESCE(SUM(output_tokens), 0) AS output_tokens' )
            ->selectRaw( 'COALESCE(SUM(estimated_cost_usd), 0) AS cost_usd' )
            ->selectRaw( 'COUNT(*) AS events' )
            ->groupBy( 'bucket' )
            ->orderBy( 'bucket' )
            ->get()
            ->map( fn ( $row ) => [
                'bucket'        => (string) $row->bucket,
                'input_tokens'  => (int) $row->input_tokens,
                'output_tokens' => (int) $row->output_tokens,
                'cost_usd'      => (float) $row->cost_usd,
                'events'        => (int) $row->events,
            ] )
            ->all();
    }

    /**
     * Delete rows older than the given cutoff. Returns the row count removed.
     *
     * @since 1.0.0
     *
     * @param  DateTimeInterface  $cutoff  Rows with `created_at <` cutoff are removed.
     *
     * @return int
     */
    public function purgeOlderThan( DateTimeInterface $cutoff ): int
    {
        return $this->table()->where( 'created_at', '<', $cutoff )->delete();
    }

    /**
     * Base query builder for the events table.
     *
     * @since 1.0.0
     *
     * @return Builder
     */
    protected function table(): Builder
    {
        return $this->db->connection()->table( 'ai_usage_events' );
    }

    /**
     * Apply an optional `created_at BETWEEN` filter to the builder.
     *
     * @since 1.0.0
     *
     * @param  Builder                 $query  Base query.
     * @param  DateTimeInterface|null  $from   Optional lower bound.
     * @param  DateTimeInterface|null  $to     Optional upper bound.
     *
     * @return Builder
     */
    protected function range( Builder $query, ?DateTimeInterface $from, ?DateTimeInterface $to ): Builder
    {
        if ( null !== $from ) {
            $query->where( 'created_at', '>=', $from );
        }

        if ( null !== $to ) {
            $query->where( 'created_at', '<=', $to );
        }

        return $query;
    }

    /**
     * Return the SQL expression that produces a bucket label.
     *
     * SQLite is used in tests and by many hosts; the expression here is
     * portable across SQLite / MySQL / MariaDB / Postgres via the standard
     * `strftime` (SQLite) or `DATE_FORMAT` (MySQL). To keep the surface
     * small we ship a SQLite-compatible form and rely on downstream apps
     * to override if they need a different dialect.
     *
     * @since 1.0.0
     *
     * @param  string  $bucket  `day`, `week`, or `month`.
     *
     * @return string
     */
    protected function bucketExpression( string $bucket ): string
    {
        return match ( $bucket ) {
            'week'  => "strftime('%Y-%W', created_at)",
            'month' => "strftime('%Y-%m', created_at)",
            default => "strftime('%Y-%m-%d', created_at)",
        };
    }
}
