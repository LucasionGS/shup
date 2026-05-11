@extends('layouts.main')

@section('content')

@php
    $user = auth()->user();
    $pastes = \App\Models\PasteBin::where('user_id', $user->id)->get();
@endphp

<div class="app-panel">
    <div class="panel-header">
        <div>
            <div class="panel-eyebrow">Text drops</div>
            <h1 class="panel-title">Upload Paste</h1>
            <p class="panel-subtitle">Store quick snippets, logs, and notes with Shup short codes.</p>
        </div>
    </div>

    <form action="{{ url('p') }}?_back=1" method="POST" class="form-stack">
        @csrf
        <div class="grid gap-3 md:grid-cols-[1fr_auto] md:items-start">
            <textarea name="content" placeholder="Paste content" required></textarea>
            <button type="submit" class="btn-primary md:min-w-36">Upload</button>
        </div>
    </form>

    @if (session('short_url'))
        <div class="alert-success mt-6" role="alert">
            <div class="font-semibold">Paste uploaded</div>
            <div class="mt-3 flex flex-col gap-2 sm:flex-row">
                <input type="text" value="{{ session('short_url') }}" readonly>
                <button class="clipboard btn-secondary" data-clipboard-text="{{ session('short_url') }}">Copy</button>
            </div>
        </div>
    @endif

    <div class="mt-8 border-t border-white/10 pt-8">
        <div class="panel-header">
            <div>
                <div class="panel-eyebrow">Library</div>
                <h2>Your Paste Bins</h2>
                <p class="panel-subtitle">Open, copy, and remove text uploads from your account.</p>
            </div>
        </div>
    
        @if($pastes->isEmpty())
            <div class="surface-card text-center">
                <p>You haven't created any paste bins yet.</p>
            </div>
        @else
            <div class="table-shell">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Short Code</th>
                            <th>Created At</th>
                            <th>Expires</th>
                            <th>Password Protected</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($pastes as $paste)
                            <tr>
                                <td>{{ $paste->short_code }}</td>
                                <td>{{ $paste->created_at->format('Y-m-d H:i') }}</td>
                                <td class="text-center">
                                    @if($paste->expires)
                                        <span class="status-pill status-pill--danger">{{ \Carbon\Carbon::parse($paste->expires)->diffForHumans() }}</span>
                                    @else
                                        <span class="status-pill status-pill--muted">Never</span>
                                    @endif
                                </td>
                                <td class="text-center">
                                    @if($paste->password)
                                        <span class="status-pill status-pill--danger">Protected</span>
                                    @else
                                        <span class="status-pill status-pill--muted">Open</span>
                                    @endif
                                </td>
                                <td>
                                    <div class="flex flex-wrap justify-center gap-2">
                                        <button class="clipboard btn-secondary btn-small" data-clipboard-text="{{ url("p/{$paste->short_code}") }}">Copy</button>
                                        <a href="{{ url("p/{$paste->short_code}") }}" class="btn-secondary btn-small">View</a>
                                        <form action="{{ url("p/{$paste->short_code}?force=1&_back=1") }}" method="POST"
                                            onsubmit="return confirm('Are you sure you want to delete this paste bin? This action cannot be undone.');"
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
        @endif
    </div>

    <div class="mt-8 border-t border-white/10 pt-8">
        <div class="panel-header mb-3">
            <div>
                <div class="panel-eyebrow">Automation</div>
                <h2>Use with ShareX</h2>
            </div>
        </div>
        <code class="codeblock">{
    "Version": "16.1.0",
    "Name": "Shup PasteBin Upload",
    "DestinationType": "TextUploader",
    "RequestMethod": "POST",
    "Headers": {
        "Authorization": "{{ $user->api_token }}"
    },
    "RequestURL": "{{ url("p") }}",
    "Body": "MultipartFormData",
    "Arguments": {
        "content": "{input}"
    },
    "URL": "{json:url}"
}
</code>
    </div>
</div>
@endsection
