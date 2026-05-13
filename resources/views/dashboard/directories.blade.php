@extends('layouts.main')

@section('content')

@php
    $user = auth()->user();
    $directories = \App\Models\Directory::withCount(['items', 'files'])
        ->where('user_id', $user->id)
        ->orderBy('created_at', 'desc')
        ->get();
@endphp

<div class="app-panel">
    <div class="panel-header">
        <div>
            <div class="panel-eyebrow">Directories</div>
            <h1 class="panel-title">New Directory</h1>
            <p class="panel-subtitle">Create a shareable file tree with nested folders, empty folders, and ZIP download.</p>
        </div>
    </div>

    @if (session('directory_url'))
        <div class="alert-success mb-6" role="alert">
            <div class="font-semibold">Directory created</div>
            <div class="mt-3 flex flex-col gap-2 sm:flex-row">
                <input type="text" value="{{ session('directory_url') }}" readonly>
                <button class="clipboard btn-secondary" data-clipboard-text="{{ session('directory_url') }}">Copy</button>
            </div>
        </div>
    @endif

    @if (session('directory_info'))
        <div class="alert-success mb-6" role="alert">
            {{ session('directory_info') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="alert-error mb-6" role="alert">
            {{ $errors->first() }}
        </div>
    @endif

    <div data-upload-scope>
        <form
            action="{{ url('d') }}?_back=1"
            data-upload-progress
            data-directory-upload
            data-upload-action="{{ url('d') }}"
            data-upload-refresh-target="[data-directories-library]"
            method="POST"
            class="surface-card form-stack"
            enctype="multipart/form-data"
        >
            @csrf
            <div class="form-grid">
                <div>
                    <label for="directory_name" class="field-label">Name</label>
                    <input type="text" id="directory_name" name="name" maxlength="120" required>
                </div>
                <div>
                    <label for="directory_expires" class="field-label">Expiration</label>
                    <select name="expires" id="directory_expires">
                        <option value="">No expiry</option>
                        <option value="60">1 hour</option>
                        <option value="1440">1 day</option>
                        <option value="10080">7 days</option>
                        <option value="43200">30 days</option>
                    </select>
                </div>
            </div>
            <div>
                <label for="directory_description" class="field-label">Description</label>
                <textarea id="directory_description" name="description" maxlength="1000" rows="3"></textarea>
            </div>
            <div class="form-grid">
                <div>
                    <label for="directory_password" class="field-label">Password</label>
                    <input type="password" id="directory_password" name="password" autocomplete="new-password" placeholder="Optional">
                </div>
                <div>
                    <label for="directory_files" class="field-label">Upload Folder</label>
                    <input type="file" id="directory_files" name="files[]" webkitdirectory directory multiple>
                </div>
            </div>
            <button type="submit" class="btn-primary" data-upload-submit>Create Directory</button>
        </form>

        <div class="surface-card mt-4 hidden" data-upload-progress-container>
            <div class="flex justify-between items-center mb-2">
                <span class="text-sm font-medium">Uploading...</span>
                <span class="text-sm font-medium" style="color: var(--accent);" data-upload-progress-percent>0%</span>
            </div>
            <div class="progress-track h-3 overflow-hidden rounded-md">
                <div
                    class="progress-bar h-3 rounded-md transition-all duration-300"
                    style="width: 0%"
                    data-upload-progress-bar
                ></div>
            </div>
            <p class="helper-text" data-upload-progress-status>Preparing upload...</p>
        </div>

        <div class="alert-success mt-6 hidden" role="alert" data-upload-result>
            <div class="font-semibold">Directory created</div>
            <div class="mt-3 flex flex-col gap-2 sm:flex-row">
                <input type="text" value="" readonly data-upload-result-url>
                <button class="clipboard btn-secondary" data-clipboard-text="" data-upload-result-copy>Copy</button>
            </div>
        </div>
    </div>

    <div class="mt-8 border-t border-white/10 pt-8" data-directories-library>
        <div class="panel-header">
            <div>
                <div class="panel-eyebrow">Library</div>
                <h2>Your Directories</h2>
                <p class="panel-subtitle">Open, copy, and remove shared file trees.</p>
            </div>
        </div>

        @if($directories->isEmpty())
            <div class="surface-card text-center">
                <p>You haven't created any directories yet.</p>
            </div>
        @else
            <div class="table-shell">
                <table class="data-table min-w-[920px]">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Files</th>
                            <th>Items</th>
                            <th>Size</th>
                            <th>Protection</th>
                            <th>Expires</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($directories as $directory)
                            <tr>
                                <td>
                                    <a href="{{ url("d/$directory->short_code") }}">{{ $directory->name }}</a>
                                    <div class="helper-text">{{ $directory->short_code }}</div>
                                </td>
                                <td class="text-center">{{ $directory->files_count }}</td>
                                <td class="text-center">{{ $directory->items_count }}</td>
                                <td>{{ \App\Models\File::reduceFileSize($directory->size) }}</td>
                                <td class="text-center">
                                    @if($directory->password)
                                        <span class="status-pill status-pill--danger">Protected</span>
                                    @else
                                        <span class="status-pill status-pill--muted">Open</span>
                                    @endif
                                </td>
                                <td class="text-center">
                                    @if($directory->expires)
                                        <span class="status-pill status-pill--danger">{{ $directory->expires->diffForHumans() }}</span>
                                    @else
                                        <span class="status-pill status-pill--muted">Never</span>
                                    @endif
                                </td>
                                <td>
                                    <div class="flex flex-wrap justify-center gap-2">
                                        <button class="clipboard btn-secondary btn-small" data-clipboard-text="{{ url("d/$directory->short_code") }}">Copy</button>
                                        <a href="{{ url("d/$directory->short_code") }}" class="btn-secondary btn-small">Open</a>
                                        <form action="{{ url("d/$directory->short_code?_back=1") }}" method="POST" onsubmit="return confirm('Delete this directory?');">
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