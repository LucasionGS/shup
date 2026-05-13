@extends('layouts.main')

@section('content')

@php
    $user = auth()->user();
    $files = \App\Models\File::where('user_id', $user->id)->orderBy('created_at', 'desc')->get();
    $pastes = \App\Models\PasteBin::where('user_id', $user->id)->orderBy('created_at', 'desc')->get();
    $shortUrls = \App\Models\ShortURL::where('user_id', $user->id)->orderBy('created_at', 'desc')->get();
    $bundles = \App\Models\Bundle::withCount('items')->where('user_id', $user->id)->orderBy('created_at', 'desc')->get();
@endphp

<div class="app-panel">
    <div class="panel-header">
        <div>
            <div class="panel-eyebrow">Collections</div>
            <h1 class="panel-title">Create Bundle</h1>
            <p class="panel-subtitle">Group files, paste bins, and short links into one shareable Shup page.</p>
        </div>
    </div>

    @if (session('bundle_url'))
        <div class="alert-success mb-6" role="alert">
            <div class="font-semibold">Bundle created</div>
            <div class="mt-3 flex flex-col gap-2 sm:flex-row">
                <input type="text" value="{{ session('bundle_url') }}" readonly>
                <button class="clipboard btn-secondary" data-clipboard-text="{{ session('bundle_url') }}">Copy</button>
            </div>
        </div>
    @endif

    @if (session('bundle_info'))
        <div class="alert-success mb-6" role="alert">
            {{ session('bundle_info') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="alert-error mb-6" role="alert">
            {{ $errors->first() }}
        </div>
    @endif

    <form action="{{ url('b') }}?_back=1" method="POST" class="surface-card form-stack">
        @csrf
        <div class="form-grid">
            <div>
                <label for="name" class="field-label">Name</label>
                <input type="text" id="name" name="name" maxlength="120" required>
            </div>
            <div>
                <label for="expires" class="field-label">Expiration</label>
                <select name="expires" id="expires">
                    <option value="">No expiry</option>
                    <option value="60">1 hour</option>
                    <option value="1440">1 day</option>
                    <option value="10080">7 days</option>
                    <option value="43200">30 days</option>
                </select>
            </div>
        </div>
        <div>
            <label for="description" class="field-label">Description</label>
            <textarea id="description" name="description" maxlength="1000" rows="3"></textarea>
        </div>
        <div>
            <label for="password" class="field-label">Password</label>
            <input type="password" id="password" name="password" autocomplete="new-password" placeholder="Optional">
        </div>

        <div class="bundle-resource-grid">
            <div>
                <h2 class="mb-3">Files</h2>
                <div class="bundle-resource-list">
                    @forelse($files as $file)
                        <label class="bundle-resource-option">
                            <input type="checkbox" name="items[]" value="file:{{ $file->id }}">
                            <span>
                                <strong>{{ $file->original_name }}</strong>
                                <small>{{ \App\Models\File::reduceFileSize($file->size) }}</small>
                            </span>
                        </label>
                    @empty
                        <p class="muted-text text-sm">No files yet.</p>
                    @endforelse
                </div>
            </div>
            <div>
                <h2 class="mb-3">Pastes</h2>
                <div class="bundle-resource-list">
                    @forelse($pastes as $paste)
                        <label class="bundle-resource-option">
                            <input type="checkbox" name="items[]" value="paste:{{ $paste->id }}">
                            <span>
                                <strong>{{ $paste->short_code }}</strong>
                                <small>{{ $paste->created_at->format('Y-m-d H:i') }}</small>
                            </span>
                        </label>
                    @empty
                        <p class="muted-text text-sm">No pastes yet.</p>
                    @endforelse
                </div>
            </div>
            <div>
                <h2 class="mb-3">Short URLs</h2>
                <div class="bundle-resource-list">
                    @forelse($shortUrls as $shortUrl)
                        <label class="bundle-resource-option">
                            <input type="checkbox" name="items[]" value="short_url:{{ $shortUrl->id }}">
                            <span>
                                <strong>{{ $shortUrl->short_code }}</strong>
                                <small>{{ $shortUrl->url }}</small>
                            </span>
                        </label>
                    @empty
                        <p class="muted-text text-sm">No short URLs yet.</p>
                    @endforelse
                </div>
            </div>
        </div>

        <button type="submit" class="btn-primary">Create Bundle</button>
    </form>

    <div class="mt-8 border-t border-white/10 pt-8">
        <div class="panel-header">
            <div>
                <div class="panel-eyebrow">Library</div>
                <h2>Your Bundles</h2>
                <p class="panel-subtitle">Copy, open, and retire grouped shares from one place.</p>
            </div>
        </div>

        @if($bundles->isEmpty())
            <div class="surface-card text-center">
                <p>You haven't created any bundles yet.</p>
            </div>
        @else
            <div class="table-shell">
                <table class="data-table min-w-[860px]">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Items</th>
                            <th>Protection</th>
                            <th>Expires</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($bundles as $bundle)
                            <tr>
                                <td>
                                    <a href="{{ url("b/$bundle->short_code") }}">{{ $bundle->name }}</a>
                                    <div class="helper-text">{{ $bundle->short_code }}</div>
                                </td>
                                <td class="text-center">{{ $bundle->items_count }}</td>
                                <td class="text-center">
                                    @if($bundle->password)
                                        <span class="status-pill status-pill--danger">Protected</span>
                                    @else
                                        <span class="status-pill status-pill--muted">Open</span>
                                    @endif
                                </td>
                                <td class="text-center">
                                    @if($bundle->expires)
                                        <span class="status-pill status-pill--danger">{{ $bundle->expires->diffForHumans() }}</span>
                                    @else
                                        <span class="status-pill status-pill--muted">Never</span>
                                    @endif
                                </td>
                                <td>
                                    <div class="flex flex-wrap justify-center gap-2">
                                        <button class="clipboard btn-secondary btn-small" data-clipboard-text="{{ url("b/$bundle->short_code") }}">Copy</button>
                                        <a href="{{ url("b/$bundle->short_code") }}" class="btn-secondary btn-small">Open</a>
                                        <form action="{{ url("b/$bundle->short_code?_back=1") }}" method="POST" onsubmit="return confirm('Delete this bundle?');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn-danger btn-small">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
@endsection