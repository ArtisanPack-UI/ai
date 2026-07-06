@props(['label' => null, 'error' => null, 'hint' => null])
<label data-stub="artisanpack-input">
    @if ( $label )<span>{{ $label }}</span>@endif
    <input {{ $attributes }} />
    @if ( $hint )<small>{{ $hint }}</small>@endif
    @if ( $error )<span data-error>{{ $error }}</span>@endif
</label>
