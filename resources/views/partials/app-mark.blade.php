@php
    $class = $class ?? 'public-brand';
    $alt = $alt ?? env('APP_NAME');
@endphp

<span class="{{ $class }}">
    <img src="{{ asset('shup.png') }}" alt="{{ $alt }}" class="app-icon-image">
</span>
