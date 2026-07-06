<?php

/**
 * AI Usage dashboard (Livewire).
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ai\Livewire\Admin;

use ArtisanPackUI\Ai\Repositories\AiUsageRepository;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Read-only dashboard summarising `ai_usage_events` for a given date range.
 *
 * Defaults to the current calendar month. Every widget re-queries when the
 * range changes — the repository is cheap enough that we don't need a
 * dedicated cache layer at this scope. When no rows fall inside the range,
 * an empty state renders instead of empty stat cards.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */
class UsageDashboard extends Component
{
    /**
     * ISO date (YYYY-MM-DD) lower bound. Inclusive.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public string $from = '';

    /**
     * ISO date (YYYY-MM-DD) upper bound. Inclusive.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public string $to = '';

    /**
     * Feature key of the drilldown row currently open (null = list view).
     *
     * Marked `#[Locked]` so the only path into this property is
     * `openDrilldown()` — a Livewire client can't PATCH it directly. This
     * keeps future per-feature RBAC gates authoritative (a role that lacks
     * access to feature X can't observe its usage rows by tampering with
     * this string).
     *
     * @since 1.0.0
     *
     * @var string|null
     */
    #[Locked]
    public ?string $drilldownFeature = null;

    /**
     * Initialise the range to the current calendar month.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function mount(): void
    {
        $now        = Carbon::now();
        $this->from = $now->copy()->startOfMonth()->toDateString();
        $this->to   = $now->copy()->endOfMonth()->toDateString();
    }

    /**
     * Reset the drilldown when the range changes.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function updatedFrom(): void
    {
        $this->drilldownFeature = null;
    }

    /**
     * Reset the drilldown when the range changes.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function updatedTo(): void
    {
        $this->drilldownFeature = null;
    }

    /**
     * Open the drilldown for a feature.
     *
     * @since 1.0.0
     *
     * @param  string  $featureKey  Feature key to drill into.
     *
     * @return void
     */
    public function openDrilldown( string $featureKey ): void
    {
        $this->drilldownFeature = $featureKey;
    }

    /**
     * Close the drilldown, returning to the summary view.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function closeDrilldown(): void
    {
        $this->drilldownFeature = null;
    }

    /**
     * Aggregated totals for the current range.
     *
     * @since 1.0.0
     *
     * @return array{ input_tokens: int, output_tokens: int, cost_usd: float, events: int }
     */
    #[Computed]
    public function totals(): array
    {
        return $this->repository()->totals( $this->fromDate(), $this->toDate() );
    }

    /**
     * Per-feature breakdown for the current range.
     *
     * @since 1.0.0
     *
     * @return list<array{ feature_key: string, input_tokens: int, output_tokens: int, cost_usd: float, events: int }>
     */
    #[Computed]
    public function byFeature(): array
    {
        return $this->repository()->byFeature( $this->fromDate(), $this->toDate() );
    }

    /**
     * Daily bucket totals for the current range (drives the chart).
     *
     * @since 1.0.0
     *
     * @return list<array{ bucket: string, input_tokens: int, output_tokens: int, cost_usd: float, events: int }>
     */
    #[Computed]
    public function daily(): array
    {
        return $this->repository()->byPeriod( 'day', $this->fromDate(), $this->toDate() );
    }

    /**
     * Individual event rows for the currently open drilldown.
     *
     * We cap at the 100 most recent rows to keep the view snappy; a full
     * exporter is out of scope for the initial dashboard.
     *
     * @since 1.0.0
     *
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function drilldownRows(): array
    {
        if ( null === $this->drilldownFeature ) {
            return [];
        }

        /** @var ConnectionResolverInterface $db */
        $db = app( ConnectionResolverInterface::class );

        $rows = $db->connection()
            ->table( 'ai_usage_events' )
            ->where( 'feature_key', $this->drilldownFeature )
            ->when(
                null !== $this->fromDate(),
                fn ( $query ) => $query->where( 'created_at', '>=', $this->fromDate() ),
            )
            ->when(
                null !== $this->toDate(),
                fn ( $query ) => $query->where( 'created_at', '<=', $this->toDate() ),
            )
            ->orderByDesc( 'created_at' )
            ->limit( 100 )
            ->get();

        return $rows
            ->map( fn ( $row ): array => [
                'created_at'    => (string) $row->created_at,
                'provider'      => (string) ( $row->provider ?? '' ),
                'model'         => (string) $row->model,
                'input_tokens'  => (int) $row->input_tokens,
                'output_tokens' => (int) $row->output_tokens,
                'cost_usd'      => (float) $row->estimated_cost_usd,
                'cache_hit'     => (bool) $row->cache_hit,
            ] )
            ->all();
    }

    /**
     * Render the view.
     *
     * @since 1.0.0
     *
     * @return View
     */
    public function render(): View
    {
        return view( 'artisanpack-ai::admin.livewire.usage-dashboard' );
    }

    /**
     * Convert `$from` to a Carbon instance at start-of-day, or null when blank.
     *
     * @since 1.0.0
     *
     * @return Carbon|null
     */
    protected function fromDate(): ?Carbon
    {
        return $this->parseBound( $this->from, endOfDay: false );
    }

    /**
     * Convert `$to` to a Carbon instance at end-of-day, or null when blank.
     *
     * @since 1.0.0
     *
     * @return Carbon|null
     */
    protected function toDate(): ?Carbon
    {
        return $this->parseBound( $this->to, endOfDay: true );
    }

    /**
     * Parse a wire-model-bound date string into a Carbon bound.
     *
     * `wire:model.live` sends partial values on some clients (and Livewire
     * itself will accept any string a crafted payload posts), so
     * `Carbon::parse` on the raw input would throw and 500 the whole
     * dashboard mid-typing. Treat any unparseable string as "no bound" —
     * the widgets fall back to the full history until a valid ISO date
     * arrives.
     *
     * @since 1.0.0
     *
     * @param  string  $raw       Raw input string.
     * @param  bool    $endOfDay  Whether to snap to end-of-day (upper bound).
     *
     * @return Carbon|null
     */
    protected function parseBound( string $raw, bool $endOfDay ): ?Carbon
    {
        $trimmed = trim( $raw );

        if ( '' === $trimmed ) {
            return null;
        }

        try {
            $parsed = Carbon::parse( $trimmed );
        } catch ( InvalidFormatException $exception ) {
            return null;
        }

        return $endOfDay ? $parsed->endOfDay() : $parsed->startOfDay();
    }

    /**
     * Resolve the usage repository lazily so tests that swap it via the
     * container get the updated binding.
     *
     * @since 1.0.0
     *
     * @return AiUsageRepository
     */
    protected function repository(): AiUsageRepository
    {
        return app( AiUsageRepository::class );
    }
}
