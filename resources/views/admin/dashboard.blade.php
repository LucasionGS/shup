@extends('layouts.main')

@section('content')

<div class="app-panel">
    <div class="panel-header">
        <div>
            <div class="panel-eyebrow">Admin console</div>
            <h1 class="panel-title">Instance Overview</h1>
            <p class="panel-subtitle">Usage, activity, and storage health across every account.</p>
        </div>
        <a href="{{ route('admin.users') }}" class="btn-secondary">Manage Users</a>
    </div>

    @if (session('account_info'))
        <div class="alert-success mb-4" role="alert">{{ session('account_info') }}</div>
    @endif

    @if ($errors->any())
        <div class="alert-error mb-4" role="alert">{{ $errors->first() }}</div>
    @endif

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <div class="stat-card">
            <div class="stat-label">Users</div>
            <div class="stat-value">{{ $stats['users'] }}</div>
            <div class="helper-text">{{ $stats['admins'] }} admin(s)</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Stored</div>
            <div class="stat-value">{{ \App\Models\File::reduceFileSize($stats['stored_bytes']) }}</div>
            <div class="helper-text">{{ $stats['files'] }} files, {{ $stats['directories'] }} directories</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Downloads</div>
            <div class="stat-value">{{ number_format($stats['downloads']) }}</div>
            <div class="helper-text">{{ number_format($stats['redirect_hits']) }} redirect hits</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Pastes</div>
            <div class="stat-value">{{ $stats['pastes'] }}</div>
            <div class="helper-text">{{ $stats['short_urls'] }} short URLs</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Open upload links</div>
            <div class="stat-value">{{ $stats['upload_links'] }}</div>
            <div class="helper-text">{{ $stats['in_progress_uploads'] }} upload(s) in progress</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Expiring in 24h</div>
            <div class="stat-value">{{ $stats['expiring_soon'] }}</div>
            <div class="helper-text">files scheduled for deletion</div>
        </div>
    </div>

    <div class="mt-8 border-t border-white/10 pt-8">
        <div class="panel-header">
            <div>
                <div class="panel-eyebrow">Storage</div>
                <h2>Largest Accounts</h2>
                <p class="panel-subtitle">Who is using the disk, and how close they are to their quota.</p>
            </div>
        </div>

        @if($topUsers->isEmpty())
            <div class="surface-card text-center"><p>No accounts yet.</p></div>
        @else
            <div class="table-shell">
                <table class="data-table min-w-[820px]">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Role</th>
                            <th>Used</th>
                            <th>Quota</th>
                            <th>Usage</th>
                            <th>API key last used</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($topUsers as $listedUser)
                            @php
                                $limit = (int) $listedUser->storage_limit;
                                $used = (int) $listedUser->storage_used;
                                $percent = $limit > 0 ? min(100, (int) round(($used / $limit) * 100)) : null;
                            @endphp
                            <tr>
                                <td>
                                    {{ $listedUser->name }}
                                    <div class="helper-text break-all">{{ $listedUser->email }}</div>
                                </td>
                                <td class="text-center">
                                    <span class="status-pill status-pill--muted">{{ $listedUser->getRoleName() }}</span>
                                </td>
                                <td>{{ \App\Models\File::reduceFileSize($used) }}</td>
                                <td>{{ $limit === 0 ? 'Unlimited' : \App\Models\File::reduceFileSize($limit) }}</td>
                                <td class="text-center">
                                    @if($percent === null)
                                        <span class="muted-text text-sm">&mdash;</span>
                                    @else
                                        <span class="status-pill {{ $percent >= 90 ? 'status-pill--danger' : 'status-pill--muted' }}">{{ $percent }}%</span>
                                    @endif
                                </td>
                                <td class="text-center">
                                    @if($listedUser->api_token_last_used_at)
                                        {{ $listedUser->api_token_last_used_at->diffForHumans() }}
                                    @else
                                        <span class="muted-text text-sm">Never</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <div class="mt-8 border-t border-white/10 pt-8">
        <div class="panel-header">
            <div>
                <div class="panel-eyebrow">Activity</div>
                <h2>Most Downloaded</h2>
                <p class="panel-subtitle">The busiest shares on this instance.</p>
            </div>
        </div>

        @if($popularFiles->isEmpty())
            <div class="surface-card text-center"><p>Nothing has been downloaded yet.</p></div>
        @else
            <div class="table-shell">
                <table class="data-table min-w-[720px]">
                    <thead>
                        <tr>
                            <th>File</th>
                            <th>Size</th>
                            <th>Downloads</th>
                            <th class="text-center">Open</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($popularFiles as $file)
                            <tr>
                                <td>
                                    {{ $file->original_name }}
                                    <div class="helper-text">{{ $file->short_code }}</div>
                                </td>
                                <td>{{ \App\Models\File::reduceFileSize($file->size) }}</td>
                                <td class="text-center">
                                    {{ $file->downloads }}@if($file->max_downloads)<span class="muted-text"> / {{ $file->max_downloads }}</span>@endif
                                </td>
                                <td class="text-center">
                                    <a href="{{ url("f/$file->short_code") }}" class="btn-secondary btn-small">Open</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <div class="mt-8 border-t border-white/10 pt-8">
        <div class="panel-header">
            <div>
                <div class="panel-eyebrow">Maintenance</div>
                <h2>Orphaned Files</h2>
                <p class="panel-subtitle">Blobs on disk with no database record. Nothing else in the app looks for these.</p>
            </div>
        </div>

        @php $orphanCount = count($orphans['files']) + $orphans['directory_files']; @endphp

        <div class="surface-card">
            @if($orphanCount === 0)
                <p>No orphaned files. Storage matches the database.</p>
            @else
                <p class="mb-3">
                    <strong>{{ $orphanCount }}</strong> orphaned file(s) using
                    <strong>{{ \App\Models\File::reduceFileSize($orphans['bytes']) }}</strong>.
                </p>
                @if($orphans['files'])
                    <p class="helper-text mb-3 break-all">
                        {{ implode(', ', array_slice($orphans['files'], 0, 12)) }}
                        @if(count($orphans['files']) > 12)
                            and {{ count($orphans['files']) - 12 }} more
                        @endif
                    </p>
                @endif
                <form action="{{ route('admin.pruneOrphans') }}" method="POST"
                    onsubmit="return confirm('Permanently delete {{ $orphanCount }} orphaned file(s)? This cannot be undone.');">
                    @csrf
                    <button type="submit" class="btn-danger">Delete Orphaned Files</button>
                </form>
            @endif
        </div>
    </div>
</div>
@endsection
