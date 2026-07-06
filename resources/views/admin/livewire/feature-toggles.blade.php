{{--
  AI Per-feature toggles admin page (Livewire).

  Lists every registered AI feature grouped by owning package with an
  on/off toggle per feature and a bulk enable/disable action per package.
--}}
<div class="space-y-6">
    @if ( $toast )
        @php
            $toastColor = match ( $toast['type'] ) {
                'success' => 'success',
                'error'   => 'error',
                default   => 'info',
            };
            $toastIcon = match ( $toast['type'] ) {
                'success' => 'o-check-circle',
                'error'   => 'o-exclamation-triangle',
                default   => 'o-information-circle',
            };
        @endphp

        <x-artisanpack-alert
            :color="$toastColor"
            :icon="$toastIcon"
            :title="$toast['message']"
            dismissible
            wire:key="feature-toggles-toast-{{ md5( $toast['message'] ) }}"
        />
    @endif

    <x-artisanpack-card
        :title="__( 'Per-feature toggles' )"
        :subtitle="__( 'Turn individual AI features on or off. Disabled features return early on the next agent run.' )"
    >
        <div class="mb-4">
            <x-artisanpack-input
                :label="__( 'Search features' )"
                wire:model.live.debounce.250ms="search"
                :placeholder="__( 'Filter by package, key, or description' )"
                icon="o-magnifying-glass"
            />
        </div>

        @php
            $groups = $this->groupedFeatures;
        @endphp

        @if ( empty( $groups ) )
            <div class="rounded-lg border border-dashed p-6 text-center opacity-70">
                @if ( '' === trim( $search ) )
                    {{ __( 'No AI features are registered yet. Install a package that ships an agent to see it listed here.' ) }}
                @else
                    {{ __( 'No features match ":search".', [ 'search' => $search ] ) }}
                @endif
            </div>
        @else
            <div class="space-y-4">
                @foreach ( $groups as $group )
                    <div
                        wire:key="feature-group-{{ $group['package'] }}"
                        class="rounded-lg border p-4"
                    >
                        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <h3 class="text-lg font-semibold">{{ $group['package'] }}</h3>
                                <p class="text-sm opacity-70">
                                    {{ __( ':enabled of :total enabled', [
                                        'enabled' => $group['enabled_count'],
                                        'total'   => $group['total_count'],
                                    ] ) }}
                                </p>
                            </div>

                            <div class="flex gap-2">
                                <x-artisanpack-button
                                    size="sm"
                                    color="success"
                                    wire:click="enablePackage( @js( $group['package'] ) )"
                                    :disabled="$group['enabled_count'] === $group['total_count']"
                                >
                                    {{ __( 'Enable all' ) }}
                                </x-artisanpack-button>

                                <x-artisanpack-button
                                    size="sm"
                                    color="error"
                                    wire:click="disablePackage( @js( $group['package'] ) )"
                                    :disabled="$group['enabled_count'] === 0"
                                >
                                    {{ __( 'Disable all' ) }}
                                </x-artisanpack-button>
                            </div>
                        </div>

                        <ul class="divide-y">
                            @foreach ( $group['features'] as $feature )
                                <li
                                    wire:key="feature-{{ $feature['key'] }}"
                                    class="flex flex-wrap items-center justify-between gap-3 py-3"
                                >
                                    <div class="min-w-0 flex-1">
                                        <div class="font-mono text-sm">{{ $feature['key'] }}</div>
                                        @if ( '' !== $feature['label'] && $feature['label'] !== $feature['key'] )
                                            <div class="text-sm font-medium">{{ $feature['label'] }}</div>
                                        @endif
                                        @if ( '' !== $feature['description'] )
                                            <p class="text-sm opacity-70">{{ $feature['description'] }}</p>
                                        @endif
                                    </div>

                                    <label class="flex cursor-pointer items-center gap-2">
                                        <span class="text-sm">
                                            {{ $feature['enabled'] ? __( 'Enabled' ) : __( 'Disabled' ) }}
                                        </span>
                                        <input
                                            type="checkbox"
                                            class="toggle"
                                            wire:click="toggle( @js( $feature['key'] ) )"
                                            @checked( $feature['enabled'] )
                                            data-feature-key="{{ $feature['key'] }}"
                                        />
                                    </label>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
        @endif
    </x-artisanpack-card>
</div>
