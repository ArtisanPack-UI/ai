@props(['label' => null, 'options' => [], 'optionValue' => 'id', 'optionLabel' => 'name'])
<label data-stub="artisanpack-select">
    @if ( $label )<span>{{ $label }}</span>@endif
    <select {{ $attributes }}>
        @foreach ( $options as $option )
            <option value="{{ data_get( $option, $optionValue ) }}">{{ data_get( $option, $optionLabel ) }}</option>
        @endforeach
    </select>
</label>
