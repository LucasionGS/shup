@extends('layouts.main')

@section('content')

@php
    $user = auth()->user();
    $avatarInitial = strtoupper(substr($user->name ?: $user->email ?: env("APP_NAME"), 0, 1));
    $filesCount = \App\Models\File::where('user_id', $user->id)->count();
    $urlsCount = \App\Models\ShortURL::where('user_id', $user->id)->count();
    $pasteBinsCount = \App\Models\PasteBin::where('user_id', $user->id)->count();
    $uploadLinksCount = \App\Models\UploadLink::where('user_id', $user->id)->count();
    $activeUploadLinksCount = \App\Models\UploadLink::where('user_id', $user->id)
        ->where('used', false)
        ->where(function ($query) {
            $query->whereNull('expires')->orWhere('expires', '>', now());
        })
        ->count();
    $storageLimit = $user->storage_limit;
    $storageUsedLabel = \App\Models\File::reduceFileSize($user->storage_used);
    $storageLimitLabel = $storageLimit === 0 ? "Unlimited" : \App\Models\File::reduceFileSize($storageLimit);
    $storagePercent = $storageLimit > 0 ? min(100, round(($user->storage_used / $storageLimit) * 100)) : 100;
    $memberSince = $user->created_at ? $user->created_at->format('M j, Y') : 'Unknown';
@endphp

<div class="app-panel profile-page">
    <div class="panel-header">
        <div>
            <div class="panel-eyebrow">Account</div>
            <h1 class="panel-title">Profile</h1>
            <p class="panel-subtitle">Manage your identity, avatar, API key, and storage at a glance.</p>
        </div>
        <a href="{{ route('dashboard') }}" class="btn-secondary">Dashboard</a>
    </div>

    @if (session('account_info'))
        <div class="alert-success mb-4" role="alert">
            {{ session('account_info') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="alert-error mb-4" role="alert">
            {{ $errors->first() }}
        </div>
    @endif

    <div class="profile-hero mb-6">
        <div class="profile-avatar profile-avatar--large">
            <span>{{ $avatarInitial }}</span>
            @if ($user->image)
                <img src="{{ $user->image }}" alt="{{ $user->name }}" onerror="this.remove()">
            @endif
        </div>
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-3">
                <h2 class="truncate">{{ $user->name }}</h2>
                <span class="status-pill status-pill--active">{{ $user->getRoleName() }}</span>
            </div>
            <p class="muted-text break-all">{{ $user->email }}</p>
            <div class="profile-meta mt-4">
                <span>Member since {{ $memberSince }}</span>
                <span>{{ $storageUsedLabel }} used</span>
                <span>{{ $uploadLinksCount }} upload links</span>
            </div>
        </div>
    </div>

    <div class="profile-grid">
        <form action="{{ route('updateUser', $user) }}?_back=1" method="POST" class="surface-card form-stack">
            @csrf
            @method('PUT')

            <div>
                <div class="panel-eyebrow">Details</div>
                <h2>Account Details</h2>
            </div>

            <div>
                <label for="name" class="field-label">Name</label>
                <input type="text" id="name" name="name" value="{{ old('name', $user->name) }}" required>
            </div>

            <div>
                <label for="email" class="field-label">Email</label>
                <input type="email" id="email" name="email" value="{{ old('email', $user->email) }}" required>
            </div>

            <button type="submit" class="btn-primary">Save Profile</button>
        </form>

        <div class="surface-card form-stack">
            <div>
                <div class="panel-eyebrow">Avatar</div>
                <h2>Profile Image</h2>
            </div>

            <div class="flex flex-wrap items-center gap-4">
                <div class="profile-avatar">
                    <span>{{ $avatarInitial }}</span>
                    @if ($user->image)
                        <img src="{{ $user->image }}" alt="{{ $user->name }}" onerror="this.remove()">
                    @endif
                </div>
                <div class="flex flex-wrap gap-2">
                    <a href="{{ route('files') }}" class="btn-secondary">Open Files</a>
                    @if ($user->image)
                        <form action="{{ route('updateUser', $user) }}?_back=1" method="POST">
                            @csrf
                            @method('PUT')
                            <input type="hidden" name="image" value="">
                            <button type="submit" class="btn-ghost">Remove Avatar</button>
                        </form>
                    @endif
                </div>
            </div>
        </div>

        <div class="surface-card form-stack">
            <div>
                <div class="panel-eyebrow">Access</div>
                <h2>API Key</h2>
            </div>

            <div>
                <label for="api_token" class="field-label">Token</label>
                <div class="profile-token-row">
                    <input type="text" id="api_token" value="{{ $user->api_token }}" readonly>
                    <button type="button" class="btn-secondary" data-clipboard-text="{{ $user->api_token }}">Copy</button>
                </div>
            </div>

            <a href="{{ route('resetapi') }}" class="btn-danger" onclick="return confirm('Reset your API key? Existing integrations will stop working.');">Reset API Key</a>
        </div>

        <div class="surface-card form-stack">
            <div>
                <div class="panel-eyebrow">Storage</div>
                <h2>Usage</h2>
            </div>

            <div class="flex items-center justify-between gap-4">
                <div>
                    <p class="text-sm font-semibold text-white">{{ $storageUsedLabel }} used</p>
                    <p class="helper-text">Limit: {{ $storageLimitLabel }}</p>
                </div>
                <div class="text-sm font-semibold" style="color: var(--accent);">
                    {{ $storageLimit === 0 ? "Unlimited" : $storagePercent . "%" }}
                </div>
            </div>
            <div class="storage-meter">
                <div class="storage-meter__bar" style="width: {{ $storagePercent }}%"></div>
            </div>
        </div>

        <div class="surface-card form-stack">
            <div>
                <div class="panel-eyebrow">Library</div>
                <h2>Content</h2>
            </div>

            <div class="profile-metric-list">
                <a href="{{ route('files') }}">
                    <span>Files</span>
                    <strong>{{ $filesCount }}</strong>
                </a>
                <a href="{{ route('shorturls') }}">
                    <span>Short URLs</span>
                    <strong>{{ $urlsCount }}</strong>
                </a>
                <a href="{{ route('pastes') }}">
                    <span>Paste Bins</span>
                    <strong>{{ $pasteBinsCount }}</strong>
                </a>
                <a href="{{ route('uploadlinks') }}">
                    <span>Active Upload Links</span>
                    <strong>{{ $activeUploadLinksCount }}</strong>
                </a>
            </div>
        </div>
    </div>
</div>
@endsection