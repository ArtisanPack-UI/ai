{{--
  AI Settings admin page (Livewire).

  Renders under cms-framework's admin nav at `Admin → Packages → AI → Settings`.
  Uses `x-artisanpack-*` components exclusively per the ecosystem's
  component-usage guideline; falls back to plain form elements only where a
  matching component doesn't yet exist.
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
            wire:key="ai-settings-toast-{{ md5( $toast['message'] ) }}"
        />
    @endif

    <x-artisanpack-card
        :title="__( 'Provider credentials' )"
        :subtitle="__( 'Choose a provider, drop in credentials, and verify the connection before saving.' )"
    >
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <x-artisanpack-select
                :label="__( 'Provider' )"
                wire:model.live="provider"
                :options="collect( $providers )->map( fn ( $p ) => [ 'id' => $p['slug'], 'name' => $p['label'] ] )->all()"
                option-value="id"
                option-label="name"
            />

            @php
                $currentProvider = collect( $providers )->firstWhere( 'slug', $provider ) ?? [];
                $requiresBaseUrl = (bool) ( $currentProvider['requires_base_url'] ?? false );
                $requiresApiKey  = (bool) ( $currentProvider['requires_api_key'] ?? true );
            @endphp

            @if ( $requiresApiKey )
                <x-artisanpack-input
                    :label="__( 'API key' )"
                    type="password"
                    wire:model="apiKey"
                    :placeholder="$apiKeyPresent ? '••••••••' : __( 'Enter provider API key' )"
                    :hint="$apiKeyPresent
                        ? __( 'Leave blank to keep the stored key. Type a new value to replace it.' )
                        : __( 'Stored encrypted at rest.' )"
                    :error="$errors->first( 'apiKey' )"
                />
            @endif

            @if ( $requiresBaseUrl )
                <x-artisanpack-input
                    :label="__( 'Base URL' )"
                    wire:model="baseUrl"
                    placeholder="http://127.0.0.1:11434"
                    :hint="__( 'Points at your Ollama daemon.' )"
                    :error="$errors->first( 'baseUrl' )"
                />
            @endif

            <x-artisanpack-input
                :label="__( 'Default model' )"
                wire:model="defaultModel"
                :placeholder="__( 'Applied when a feature has no per-feature override.' )"
                :error="$errors->first( 'defaultModel' )"
            />
        </div>

        <x-slot:actions>
            <x-artisanpack-button
                wire:click="testConnection"
                wire:loading.attr="disabled"
                icon="o-signal"
            >
                <span wire:loading.remove wire:target="testConnection">{{ __( 'Test connection' ) }}</span>
                <span wire:loading wire:target="testConnection">{{ __( 'Testing…' ) }}</span>
            </x-artisanpack-button>

            <x-artisanpack-button
                color="primary"
                wire:click="save"
                wire:loading.attr="disabled"
                icon="o-check"
            >
                <span wire:loading.remove wire:target="save">{{ __( 'Save settings' ) }}</span>
                <span wire:loading wire:target="save">{{ __( 'Saving…' ) }}</span>
            </x-artisanpack-button>
        </x-slot:actions>
    </x-artisanpack-card>

    @if ( ! empty( $featureOverrides ) )
        <x-artisanpack-card
            :title="__( 'Per-feature overrides (advanced)' )"
            :subtitle="__( 'Pin a different model or prompt for specific agents. Leaves the class defaults in place when blank.' )"
        >
            <div class="space-y-4">
                {{-- Index by loop position, not feature key. Feature keys are
                     dot-separated (e.g. `seo.suggest_meta_description`) and
                     Livewire treats every `.` in a wire:model path as a
                     nested segment, so a key-indexed binding silently mis-
                     nests the write and the override is dropped on save. --}}
                @foreach ( $featureOverrides as $index => $override )
                    @php
                        $featureKey    = $override['feature_key'] ?? '';
                        $featureMeta   = collect( $features )->firstWhere( 'key', $featureKey ) ?? [];
                        $packageLabel  = $featureMeta['package'] ?? '';
                        $defaultModel  = $featureMeta['default_model'] ?? '';
                    @endphp

                    <div wire:key="feature-override-{{ $index }}" class="rounded border border-base-300 p-4">
                        <div class="mb-2 flex items-center justify-between">
                            <div>
                                <p class="font-semibold">{{ $featureKey }}</p>
                                <p class="text-xs opacity-70">
                                    {{ $packageLabel }}
                                    · {{ __( 'default model' ) }}: <code>{{ $defaultModel }}</code>
                                </p>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <x-artisanpack-input
                                :label="__( 'Model override' )"
                                wire:model="featureOverrides.{{ $index }}.model"
                                :placeholder="__( 'Leave blank to inherit the default.' )"
                            />
                            <x-artisanpack-textarea
                                :label="__( 'Instructions override' )"
                                wire:model="featureOverrides.{{ $index }}.instructions"
                                :placeholder="__( 'Leave blank to inherit the class default prompt.' )"
                                rows="4"
                            />
                        </div>
                    </div>
                @endforeach
            </div>
        </x-artisanpack-card>
    @endif
</div>
