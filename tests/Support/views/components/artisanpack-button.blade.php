@props(['color' => 'default', 'icon' => null, 'size' => 'md'])
<button data-stub="artisanpack-button" data-color="{{ $color }}" data-size="{{ $size }}" {{ $attributes }}>
    {{ $slot }}
</button>
