@extends('layouts.main')

@section('content')

@php
    $user = auth()->user();
    $filesCount = \App\Models\File::where('user_id', $user->id)->count();
    $urlsCount = \App\Models\ShortURL::where('user_id', $user->id)->count();
    $pasteBinsCount = \App\Models\PasteBin::where('user_id', $user->id)->count();
    $uploadLinksCount = \App\Models\UploadLink::where('user_id', $user->id)->count();
    $directoriesCount = \App\Models\Directory::where('user_id', $user->id)->count();
    $storageLimit = $user->storage_limit;
    $storageUsedLabel = \App\Models\File::reduceFileSize($user->storage_used);
    $storageLimitLabel = $storageLimit === 0 ? "Unlimited" : \App\Models\File::reduceFileSize($storageLimit);
    $storagePercent = $storageLimit > 0 ? min(100, round(($user->storage_used / $storageLimit) * 100)) : 100;
@endphp

<div class="app-panel">
    <div class="panel-header">
        <div>
            <div class="panel-eyebrow">Command center</div>
            <h1 class="panel-title">Your Dashboard</h1>
            <p class="panel-subtitle">Welcome back, {{ $user->name }}. Your files, links, and snippets are ready when you are.</p>
        </div>
        <a href="{{ route('resetapi') }}" class="btn-secondary">Reset API Key</a>
    </div>

    <div class="surface-card mb-6">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="text-sm font-semibold text-white">Storage</p>
                <p class="helper-text">{{ $storageUsedLabel }} used of {{ $storageLimitLabel }}</p>
            </div>
            <div class="text-sm font-semibold" style="color: var(--accent);">
                {{ $storageLimit === 0 ? "Unlimited" : $storagePercent . "%" }}
            </div>
        </div>
        <div class="storage-meter">
            <div class="storage-meter__bar" style="width: {{ $storagePercent }}%"></div>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
        <div class="stat-card">
            <div>
                <p class="stat-label">Files</p>
                <div class="stat-value">{{ $filesCount }}</div>
                <h2 class="mt-3">Uploaded Files</h2>
                <p class="mt-2 text-sm">Encrypted storage, expiring files, and protected downloads.</p>
            </div>
            <a href="{{ route('files') }}" class="btn-secondary">Open Files</a>
        </div>
        <div class="stat-card">
            <div>
                <p class="stat-label">URLs</p>
                <div class="stat-value">{{ $urlsCount }}</div>
                <h2 class="mt-3">Short Links</h2>
                <p class="mt-2 text-sm">Compact redirects with hit tracking.</p>
            </div>
            <a href="{{ route('shorturls') }}" class="btn-secondary">Open URLs</a>
        </div>
        <div class="stat-card">
            <div>
                <p class="stat-label">Directories</p>
                <div class="stat-value">{{ $directoriesCount }}</div>
                <h2 class="mt-3">File Trees</h2>
                <p class="mt-2 text-sm">Nested folders with file-manager sharing.</p>
            </div>
            <a href="{{ route('directories') }}" class="btn-secondary">Open Directories</a>
        </div>
        <div class="stat-card">
            <div>
                <p class="stat-label">Pastes</p>
                <div class="stat-value">{{ $pasteBinsCount }}</div>
                <h2 class="mt-3">Paste Bins</h2>
                <p class="mt-2 text-sm">Text snippets with optional protection.</p>
            </div>
            <a href="{{ route('pastes') }}" class="btn-secondary">Open Pastes</a>
        </div>
        <div class="stat-card">
            <div>
                <p class="stat-label">Upload Links</p>
                <div class="stat-value">{{ $uploadLinksCount }}</div>
                <h2 class="mt-3">Guest Uploads</h2>
                <p class="mt-2 text-sm">One-time links for collecting files into your account.</p>
            </div>
            <a href="{{ route('uploadlinks') }}" class="btn-secondary">Manage Links</a>
        </div>
    </div>
</div>
@endsection
