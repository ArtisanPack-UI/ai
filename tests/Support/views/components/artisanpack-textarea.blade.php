@props(['label' => null, 'error' => null, 'hint' => null, 'rows' => 3])
<label data-stub="artisanpack-textarea">
    @if ( $label )<span>{{ $label }}</span>@endif
    <textarea rows="{{ $rows }}" {{ $attributes }}>{{ $slot }}</textarea>
    @if ( $hint )<small>{{ $hint }}</small>@endif
    @if ( $error )<span data-error>{{ $error }}</span>@endif
</label>
