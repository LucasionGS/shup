@extends('layouts.main')

@section('content')
@php
    $query = $password ? ['pwd' => $password] : [];
    $queryString = $query ? '?' . http_build_query($query) : '';
    $directoryPathUrl = function (string $path = '') use ($directory, $queryString) {
        return url('/d/' . $directory->short_code . ($path ? '/' . \App\Models\DirectoryItem::encodePath($path) : '')) . $queryString;
    };
    $directoryPreviewUrl = function (string $path = '') use ($directory, $query) {
        $previewQuery = array_merge($query, ['preview' => 1]);

        return url('/d/' . $directory->short_code . ($path ? '/' . \App\Models\DirectoryItem::encodePath($path) : '')) . '?' . http_build_query($previewQuery);
    };
    $directoryZipUrl = function (string $path = '') use ($directory, $queryString) {
        return url('/d/' . $directory->short_code . '/-/zip' . ($path ? '/' . \App\Models\DirectoryItem::encodePath($path) : '')) . $queryString;
    };
    $currentName = $currentPath === '' ? $directory->name : \App\Models\DirectoryItem::nameFromPath($currentPath);
@endphp

<div class="app-panel directory-manager" data-directory-manager>
    <div class="panel-header">
        <div class="min-w-0">
            <div class="panel-eyebrow">Directory</div>
            <h1 class="panel-title truncate">{{ $currentName }}</h1>
            @if($directory->description && $currentPath === '')
                <p class="panel-subtitle">{{ $directory->description }}</p>
            @endif
        </div>
        <div class="directory-actions">
            <button type="button" class="btn-secondary" data-clipboard-text="{{ $directoryPathUrl($currentPath) }}">Copy</button>
            <a href="{{ $directoryZipUrl($currentPath) }}" class="btn-primary">Download ZIP</a>
            @if($isOwner)
                <a href="{{ route('directories') }}" class="btn-secondary">Directories</a>
            @endif
        </div>
    </div>

    <nav class="directory-breadcrumbs" aria-label="Breadcrumb">
        @foreach($breadcrumbs as $index => $breadcrumb)
            @if($index > 0)
                <span>/</span>
            @endif
            <a href="{{ $directoryPathUrl($breadcrumb['path']) }}">{{ $breadcrumb['name'] }}</a>
        @endforeach
    </nav>

    <div class="profile-meta mb-5">
        <span>{{ $children->count() }} items here</span>
        <span>{{ \App\Models\File::reduceFileSize($directory->size) }}</span>
        @if($directory->expires)
            <span>Expires {{ $directory->expires->diffForHumans() }}</span>
        @else
            <span>No expiry</span>
        @endif
        @if($directory->password)
            <span>Protected</span>
        @endif
    </div>

    @if (session('directory_info'))
        <div class="alert-success mb-5" role="alert">
            {{ session('directory_info') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="alert-error mb-5" role="alert">
            {{ $errors->first() }}
        </div>
    @endif

    @if($isOwner)
        <div class="directory-owner-tools mb-6" data-upload-scope>
            <form
                action="{{ url("d/$directory->short_code/-/upload?_back=1") }}"
                data-upload-progress
                data-directory-upload
                data-upload-action="{{ url("d/$directory->short_code/-/upload") }}"
                data-upload-refresh-target="[data-directory-manager]"
                method="POST"
                class="surface-card form-stack"
                enctype="multipart/form-data"
            >
                @csrf
                <input type="hidden" name="current_path" value="{{ $currentPath }}">
                <label for="directory_file_upload" class="field-label">Upload Files</label>
                <div class="form-row">
                    <input type="file" id="directory_file_upload" name="files[]" multiple required>
                    <button type="submit" class="btn-primary" data-upload-submit>Upload Files</button>
                </div>
            </form>

            <form
                action="{{ url("d/$directory->short_code/-/upload?_back=1") }}"
                data-upload-progress
                data-directory-upload
                data-upload-action="{{ url("d/$directory->short_code/-/upload") }}"
                data-upload-refresh-target="[data-directory-manager]"
                method="POST"
                class="surface-card form-stack"
                enctype="multipart/form-data"
            >
                @csrf
                <input type="hidden" name="current_path" value="{{ $currentPath }}">
                <label for="directory_folder_upload" class="field-label">Upload Folder</label>
                <div class="form-row">
                    <input type="file" id="directory_folder_upload" name="files[]" webkitdirectory directory multiple required>
                    <button type="submit" class="btn-primary" data-upload-submit>Upload Folder</button>
                </div>
            </form>

            <form action="{{ url("d/$directory->short_code/-/folders?_back=1") }}" method="POST" class="surface-card form-stack">
                @csrf
                <input type="hidden" name="current_path" value="{{ $currentPath }}">
                <label for="directory_folder_name" class="field-label">New Folder</label>
                <div class="form-row">
                    <input type="text" id="directory_folder_name" name="name" maxlength="255" required>
                    <button type="submit" class="btn-secondary">Create Folder</button>
                </div>
            </form>

            <div class="surface-card hidden" data-upload-progress-container>
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
        </div>
    @endif

    <div class="directory-list">
        @forelse($children as $item)
            <div class="directory-row">
                <div class="directory-row__preview">
                    @if($item->isFolder())
                        <span class="directory-row__icon">Folder</span>
                    @elseif(str_starts_with($item->mime ?? '', 'image/'))
                        <img src="{{ $directoryPreviewUrl($item->path) }}" class="file-preview directory-media-preview directory-media-preview--image" alt="">
                    @elseif(str_starts_with($item->mime ?? '', 'audio/'))
                        <audio src="{{ $directoryPreviewUrl($item->path) }}" class="file-preview directory-media-preview directory-media-preview--audio" controls preload="metadata"></audio>
                    @elseif(str_starts_with($item->mime ?? '', 'video/'))
                        <video src="{{ $directoryPreviewUrl($item->path) }}" class="file-preview directory-media-preview directory-media-preview--video" controls preload="metadata"></video>
                    @else
                        <span class="directory-row__icon">File</span>
                    @endif
                </div>
                <a href="{{ $directoryPathUrl($item->path) }}" class="directory-row__main">
                    <span class="directory-row__name">{{ $item->name }}</span>
                </a>
                <div class="directory-row__meta">
                    @if($item->isFile())
                        <span>{{ \App\Models\File::reduceFileSize($item->size) }}</span>
                    @else
                        <span>Folder</span>
                    @endif
                </div>
                <div class="directory-row__actions">
                    @if($item->isFolder())
                        <a href="{{ $directoryPathUrl($item->path) }}" class="btn-secondary btn-small">Open</a>
                    @else
                        <a href="{{ $directoryPathUrl($item->path) }}" class="btn-secondary btn-small">Download</a>
                    @endif
                    @if($isOwner)
                        <form action="{{ url("d/$directory->short_code/-/entries?_back=1") }}" method="POST" onsubmit="return confirm('Delete this entry?');">
                            @csrf
                            @method('DELETE')
                            <input type="hidden" name="path" value="{{ $item->path }}">
                            <button type="submit" class="btn-danger btn-small">Delete</button>
                        </form>
                    @endif
                </div>
            </div>
        @empty
            <div class="surface-card text-center">
                <p>This folder is empty.</p>
            </div>
        @endforelse
    </div>
</div>
@endsection