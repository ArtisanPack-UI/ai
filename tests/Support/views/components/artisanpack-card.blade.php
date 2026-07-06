@props([])
<div data-stub="artisanpack-card">
    @isset( $header )<div data-slot="header">{{ $header }}</div>@endisset
    <div data-slot="body">{{ $slot }}</div>
    @isset( $footer )<div data-slot="footer">{{ $footer }}</div>@endisset
</div>
