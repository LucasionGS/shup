@extends('layouts.main')

@section('content')
<div class="app-panel app-panel--compact public-bundle">
    <div class="panel-header">
        <div>
            <div class="panel-eyebrow">Bundle</div>
            <h1 class="panel-title">{{ $bundle->name }}</h1>
            @if($bundle->description)
                <p class="panel-subtitle">{{ $bundle->description }}</p>
            @endif
        </div>
    </div>

    <div class="profile-meta mb-5">
        <span>{{ $items->count() }} items</span>
        @if($bundle->expires)
            <span>Expires {{ $bundle->expires->diffForHumans() }}</span>
        @else
            <span>No expiry</span>
        @endif
        @if($bundle->password)
            <span>Protected</span>
        @endif
    </div>

    @if($items->isEmpty())
        <div class="surface-card text-center">
            <p>This bundle has no available items.</p>
        </div>
    @else
        <div class="bundle-public-list">
            @foreach($items as $item)
                <div class="bundle-public-item">
                    <div class="min-w-0">
                        <span class="status-pill status-pill--muted">{{ $item->typeLabel() }}</span>
                        <h2 class="mt-3 truncate">{{ $item->displayName() }}</h2>
                        @if($item->isProtected())
                            <p class="helper-text">Password protected</p>
                        @endif
                    </div>
                    @if($item->publicUrl())
                        <a href="{{ $item->publicUrl() }}" class="btn-secondary">Open</a>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
@endsection