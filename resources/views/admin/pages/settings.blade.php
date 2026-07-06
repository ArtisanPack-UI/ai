@extends( 'cms-framework::admin.layouts.app' )

@section( 'title', __( 'AI Settings' ) )

@section( 'content' )
    <div class="p-6">
        <h1 class="mb-4 text-2xl font-bold">{{ __( 'AI Settings' ) }}</h1>

        <livewire:artisanpack-ai.admin.settings />
    </div>
@endsection
