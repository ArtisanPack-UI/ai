{{--
  AI Usage dashboard (Livewire).

  Read-only view over `ai_usage_events` with a date-range selector, top-line
  stat cards, a daily-spend chart, and a per-feature table that drills down
  into individual event rows. Uses `x-artisanpack-*` components throughout.
--}}
<div class="space-y-6">
    <x-artisanpack-card :title="__( 'Range' )">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <x-artisanpack-input
                :label="__( 'From' )"
                type="date"
                wire:model.live="from"
            />
            <x-artisanpack-input
                :label="__( 'To' )"
                type="date"
                wire:model.live="to"
            />
        </div>
    </x-artisanpack-card>

    @php
        $totals = $this->totals;
        $hasData = $totals['events'] > 0;
    @endphp

    @if ( ! $hasData )
        <x-artisanpack-card>
            <div class="space-y-2 py-8 text-center">
                <p class="text-lg font-semibold">{{ __( 'No usage recorded for this range.' ) }}</p>
                <p class="opacity-70">
                    {{ __( 'Once agents run, their usage will show up here.' ) }}
                </p>
            </div>
        </x-artisanpack-card>
    @else
        <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
            <x-artisanpack-stat
                :title="__( 'Input tokens' )"
                :value="number_format( $totals['input_tokens'] )"
                icon="o-arrow-down-tray"
            />
            <x-artisanpack-stat
                :title="__( 'Output tokens' )"
                :value="number_format( $totals['output_tokens'] )"
                icon="o-arrow-up-tray"
            />
            <x-artisanpack-stat
                :title="__( 'Estimated cost' )"
                :value="'$' . number_format( $totals['cost_usd'], 4 )"
                icon="o-currency-dollar"
            />
            <x-artisanpack-stat
                :title="__( 'Requests' )"
                :value="number_format( $totals['events'] )"
                icon="o-bolt"
            />
        </div>

        @php
            $daily        = $this->daily;
            $chartLabels  = array_map( fn ( $row ) => $row['bucket'], $daily );
            $chartOptions = [
                'chart'   => [ 'toolbar' => [ 'show' => false ] ],
                'xaxis'   => [ 'categories' => $chartLabels ],
                'stroke'  => [ 'curve' => 'smooth', 'width' => 2 ],
                'legend'  => [ 'position' => 'top' ],
                'dataLabels' => [ 'enabled' => false ],
            ];
            $chartSeries = [
                [
                    'name' => (string) __( 'Cost (USD)' ),
                    'data' => array_map( fn ( $row ) => (float) $row['cost_usd'], $daily ),
                ],
                [
                    'name' => (string) __( 'Requests' ),
                    'data' => array_map( fn ( $row ) => (int) $row['events'], $daily ),
                ],
            ];
        @endphp

        <x-artisanpack-card :title="__( 'Daily activity' )">
            <x-artisanpack-chart
                :options="$chartOptions"
                :series="$chartSeries"
                type="bar"
                wire:key="usage-daily-chart-{{ md5( $from . '|' . $to ) }}"
            />
        </x-artisanpack-card>

        <x-artisanpack-card :title="__( 'Per-feature breakdown' )">
            <x-artisanpack-table
                :headers="[
                    [ 'key' => 'feature_key',   'label' => __( 'Feature' ) ],
                    [ 'key' => 'input_tokens',  'label' => __( 'Input' ) ],
                    [ 'key' => 'output_tokens', 'label' => __( 'Output' ) ],
                    [ 'key' => 'cost_usd',      'label' => __( 'Cost (USD)' ) ],
                    [ 'key' => 'events',        'label' => __( 'Requests' ) ],
                    [ 'key' => 'actions',       'label' => '' ],
                ]"
                :rows="$this->byFeature"
            >
                @scope( 'cell_cost_usd', $row )
                    ${{ number_format( $row['cost_usd'], 4 ) }}
                @endscope

                @scope( 'cell_actions', $row )
                    <x-artisanpack-button
                        size="sm"
                        icon="o-magnifying-glass"
                        wire:click="openDrilldown('{{ $row['feature_key'] }}')"
                    >
                        {{ __( 'View' ) }}
                    </x-artisanpack-button>
                @endscope
            </x-artisanpack-table>
        </x-artisanpack-card>

        @if ( $drilldownFeature )
            <x-artisanpack-card :title="__( 'Recent runs for :feature', [ 'feature' => $drilldownFeature ] )">
                <x-slot:actions>
                    <x-artisanpack-button
                        size="sm"
                        wire:click="closeDrilldown"
                        icon="o-x-mark"
                    >
                        {{ __( 'Close' ) }}
                    </x-artisanpack-button>
                </x-slot:actions>

                <x-artisanpack-table
                    :headers="[
                        [ 'key' => 'created_at',    'label' => __( 'When' ) ],
                        [ 'key' => 'provider',      'label' => __( 'Provider' ) ],
                        [ 'key' => 'model',         'label' => __( 'Model' ) ],
                        [ 'key' => 'input_tokens', 'label' => __( 'Input' ) ],
                        [ 'key' => 'output_tokens', 'label' => __( 'Output' ) ],
                        [ 'key' => 'cost_usd',      'label' => __( 'Cost (USD)' ) ],
                        [ 'key' => 'cache_hit',     'label' => __( 'Cache' ) ],
                    ]"
                    :rows="$this->drilldownRows"
                >
                    @scope( 'cell_cost_usd', $row )
                        ${{ number_format( $row['cost_usd'], 4 ) }}
                    @endscope

                    @scope( 'cell_cache_hit', $row )
                        {{ $row['cache_hit'] ? __( 'HIT' ) : __( 'MISS' ) }}
                    @endscope
                </x-artisanpack-table>
            </x-artisanpack-card>
        @endif
    @endif
</div>
