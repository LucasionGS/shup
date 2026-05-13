@extends('layouts.main')

@section('content')

@php
    $user = auth()->user();

    $filters = [
        "ext" => \App\Models\File::select('ext')->where('user_id', $user->id)->distinct()->get(),
        "mime" => \App\Models\File::select('mime')->where('user_id', $user->id)->distinct()->get()->map(function($item) {
            $item->mime = explode("/", $item->mime)[0];
            return $item;
        })->unique('mime'),
    ];

    $activeFilters = [
        "name" => request()->query("name"),
        "ext" => request()->query("ext"),
        "mime" => request()->query("mime"),
    ];
    
    $fq = \App\Models\File::where('user_id', $user->id)->orderBy('created_at', 'desc');
    
    if ($activeFilters["name"]) {
        $fq->where('original_name', "LIKE", "%" . $activeFilters["name"] . "%");
    }
    
    if ($activeFilters["ext"]) {
        $fq->where('ext', $activeFilters["ext"]);
    }

    if ($activeFilters["mime"]) {
        $fq->where('mime', "LIKE", $activeFilters["mime"] . "/%");
    }
    
    $count = $fq->count();

    $pageSize = 15;
    $page = request()->query("page", 0);
    $files = $fq->limit(value: $pageSize)->offset($page * $pageSize)->get();
@endphp

<div class="app-panel">
    <div class="panel-header">
        <div>
            <div class="panel-eyebrow">File vault</div>
            <h1 class="panel-title">Upload File</h1>
            <p class="panel-subtitle">Send files into your private Shup storage and share them with short, controlled links.</p>
        </div>
    </div>

    <div data-upload-scope>
        <form
            action="{{ url('f') }}?_back=1"
            data-upload-progress
            data-upload-action="{{ url('f') }}"
            data-upload-refresh-target="[data-files-library]"
            method="POST"
            class="form-stack"
            enctype="multipart/form-data"
        >
            @csrf
            <div class="form-row">
                <input type="file" name="file" required>
                <button type="submit" class="btn-primary" data-upload-submit>Upload File</button>
            </div>
            <p class="helper-text">
                Max upload size: {{ php_ini_loaded_file() ? \App\Models\File::reduceFileSize(
                    min(
                        \App\Models\File::expandPHPFileSize(ini_get('upload_max_filesize')),
                        \App\Models\File::expandPHPFileSize(ini_get('post_max_size'))
                    )
            ) : "Unknown" }}
            </p>
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
            <div class="font-semibold">File uploaded</div>
            <div class="mt-3 flex flex-col gap-2 sm:flex-row">
                <input type="text" value="" readonly data-upload-result-url>
                <button class="clipboard btn-secondary" data-clipboard-text="" data-upload-result-copy>Copy</button>
            </div>
        </div>
    </div>

    @if (session('short_url'))
        <div class="alert-success mt-6" role="alert">
            <div class="font-semibold">File uploaded</div>
            <div class="mt-3 flex flex-col gap-2 sm:flex-row">
                <input type="text" value="{{ session('short_url') }}" readonly>
                <button class="clipboard btn-secondary" data-clipboard-text="{{ session('short_url') }}">Copy</button>
            </div>
        </div>
    @endif

    <div class="mt-8 border-t border-white/10 pt-8" data-files-library>
        <div class="panel-header">
            <div>
                <div class="panel-eyebrow">Library</div>
                <h2>Your Uploaded Files</h2>
                <p class="panel-subtitle">Review uploads, grab download links, and retire files when they are no longer needed.</p>
            </div>
        </div>
    
        @if($files->isEmpty() && empty($activeFilters["ext"]) && empty($activeFilters["mime"]) && empty($activeFilters["name"]))
            <div class="surface-card text-center">
                <p>You haven't uploaded any files yet.</p>
            </div>
        @else
            <form action="{{ url()->current() }}" method="GET" class="surface-card mb-4">
                <div class="form-grid">
                    <div>
                        <label for="name" class="field-label">File name</label>
                        <input name="name" id="name" type="text" placeholder="Search by name" value="{{ $activeFilters["name"] }}">
                    </div>
                    <div>
                        <label for="ext" class="field-label">Extension</label>
                        <select name="ext" id="ext">
                            <option value="">All extensions</option>
                            @foreach($filters["ext"] as $filter)
                                <option value="{{ $filter->ext }}" @if($filter->ext == $activeFilters["ext"]) selected @endif>{{ $filter->ext }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="mime" class="field-label">MIME type</label>
                        <select name="mime" id="mime">
                            <option value="">All MIME types</option>
                            @foreach($filters["mime"] as $filter)
                                <option value="{{ $filter->mime }}" @if($activeFilters["mime"] && str_starts_with($activeFilters["mime"], $filter->mime)) selected @endif>{{ $filter->mime }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="flex items-end gap-2">
                        <button type="submit" class="btn-secondary">Filter</button>
                        @if($activeFilters["name"] || $activeFilters["ext"] || $activeFilters["mime"])
                            <a href="{{ url()->current() }}" class="btn-ghost">Clear</a>
                        @endif
                    </div>
                </div>
            </form>

            @if($files->isEmpty())
                <div class="surface-card text-center">
                    <p>No files match the current filters.</p>
                </div>
            @else
                <div class="table-shell">
                    <table class="data-table min-w-[980px]">
                        <thead>
                            <tr>
                                <th>Preview</th>
                                <th>File Name</th>
                                <th>Extension</th>
                                <th>MIME Type</th>
                                <th>Downloads</th>
                                <th>Expiration</th>
                                <th>Protection</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($files as $file)
                                <tr>
                                    <td class="w-40">
                                        @if(str_starts_with($file->mime, "image/"))
                                            <img src='{{"/f/$file->short_code"}}' class="file-preview block w-32 max-h-32 object-scale-down">
                                        @elseif(str_starts_with($file->mime, "audio/"))
                                            <audio src='{{"/f/$file->short_code"}}' class="file-preview block w-40" controls></audio>
                                        @else
                                            <span class="status-pill status-pill--muted">File</span>
                                        @endif
                                    </td>
                                    <td>{{ $file->original_name }}</td>
                                    <td>{{ $file->ext }}</td>
                                    <td>{{ $file->mime }}</td>
                                    <td class="text-center">{{ $file->downloads }}</td>
                                    <td class="text-center">
                                        @if($file->expires)
                                            <span class="status-pill status-pill--danger">{{ Carbon\Carbon::parse($file->expires)->diffForHumans() }}</span>
                                        @else
                                            <span class="status-pill status-pill--muted">No expiry</span>
                                        @endif
                                    </td>
                                    <td class="text-center">
                                        @if($file->password)
                                            <span class="status-pill status-pill--danger">Protected</span>
                                        @else
                                            <span class="status-pill status-pill--muted">Open</span>
                                        @endif
                                    </td>
                                    <td>
                                        <div class="flex flex-wrap justify-center gap-2">
                                            @if (Auth::check() && str_starts_with($file->mime, "image/") && !$file->password)
                                                <form action="{{ route("updateUserImage") }}" method="POST">
                                                    @csrf
                                                    @method('POST')
                                                    <input type="hidden" name="short_code" value="{{ $file->short_code }}">
                                                    <button type="submit" class="btn-secondary btn-small">Set Profile</button>
                                                </form>
                                            @endif
                                            <a href="{{ url("f/$file->short_code") }}" class="btn-secondary btn-small">Download</a>
                                            <form action="{{ url("f/$file->short_code?force=1&_back=1") }}" method="POST"
                                                onsubmit="return confirm('Are you sure you want to delete this file? This action cannot be undone.');"
                                            >
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

                <div class="mt-6 flex items-center justify-center gap-3">
                    @if($page > 0)
                        <a href="{{ request()->fullUrlWithQuery(['page' => $page - 1]) }}" class="btn-secondary btn-small">Previous</a>
                    @endif
                    <span class="muted-text text-sm">Page {{ $page + 1 }}</span>
                    @if($count > ($page + 1) * $pageSize)
                        <a href="{{ request()->fullUrlWithQuery(['page' => $page + 1]) }}" class="btn-secondary btn-small">Next</a>
                    @endif
                </div>
            @endif
        @endif
    </div>

    <div class="mt-8 border-t border-white/10 pt-8">
        <div class="panel-header mb-3">
            <div>
                <div class="panel-eyebrow">Automation</div>
                <h2>Use with ShareX</h2>
            </div>
        </div>
        @php
        $user = auth()->user();
        @endphp
<code class="codeblock">{
    "Version": "16.1.0",
    "Name": "Shup File Upload",
    "DestinationType": "ImageUploader, FileUploader",
    "RequestMethod": "POST",
    "Headers": {
        "Authorization": "{{ $user->api_token }}"
    },
    "RequestURL": "{{url("f")}}",
    "Body": "MultipartFormData",
    "FileFormName": "file",
    "URL": "{json:url}"
}
</code>
    </div>
</div>
@endsection
