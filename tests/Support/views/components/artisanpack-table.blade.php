@props(['headers' => [], 'rows' => []])
<table data-stub="artisanpack-table">
    <thead>
        <tr>
            @foreach ( $headers as $header )
                <th>{{ $header['label'] ?? $header['key'] ?? '' }}</th>
            @endforeach
        </tr>
    </thead>
    <tbody>
        @foreach ( $rows as $row )
            <tr>
                @foreach ( $headers as $header )
                    @php $key = $header['key'] ?? ''; @endphp
                    <td>{{ data_get( $row, $key ) }}</td>
                @endforeach
            </tr>
        @endforeach
    </tbody>
    {{-- Real @scope directives are ignored in the stub; the real component
         renders per-cell scopes via a Livewire integration. --}}
    {{ $slot }}
</table>
