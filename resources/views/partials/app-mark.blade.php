@php
    $class = $class ?? 'public-brand';
    $alt = $alt ?? config('app.name');
@endphp

<span class="{{ $class }}">
    {{-- Rendered at 36-48px; the 256px asset covers high-DPI screens. --}}
    <img src="{{ asset('shup-256.png') }}" alt="{{ $alt }}" class="app-icon-image" width="256" height="256" loading="lazy">
</span>
