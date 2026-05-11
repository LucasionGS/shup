@extends('layouts.main')

@section('content')

@php
    $user = auth()->user();
    $shortUrls = \App\Models\ShortURL::where('user_id', $user->id)->get();
@endphp

<div class="app-panel">
    <div class="panel-header">
        <div>
            <div class="panel-eyebrow">Redirects</div>
            <h1 class="panel-title">Create Short URL</h1>
            <p class="panel-subtitle">Turn long destinations into clean Shup links with hit tracking.</p>
        </div>
    </div>

    <form action="{{ url('s') }}?_back=1" method="POST" class="form-stack">
        @csrf
        <div class="form-row">
            <input type="url" name="url" placeholder="URL to shorten" required>
            <button type="submit" class="btn-primary">Shorten</button>
        </div>
        @if($user->isAdmin())
            <div class="max-w-xl">
                <label for="custom_url" class="field-label">Custom shortcode</label>
                <input type="text" name="custom_url" id="custom_url" placeholder="Optional admin override">
            </div>
        @endif
    </form>

    @if (session('short_url'))
        <div class="alert-success mt-6" role="alert">
            <div class="font-semibold">Short URL created</div>
            <div class="mt-3 flex flex-col gap-2 sm:flex-row">
                <input type="text" value="{{ session('short_url') }}" readonly>
                <button class="clipboard btn-secondary" data-clipboard-text="{{ session('short_url') }}">Copy</button>
            </div>
        </div>
    @endif
    @if (session('error'))
        <div class="alert-error mt-6" role="alert">
            {{ session('error') }}
        </div>
    @endif

    <div class="mt-8 border-t border-white/10 pt-8">
        <div class="panel-header">
            <div>
                <div class="panel-eyebrow">Library</div>
                <h2>Your Shortened URLs</h2>
                <p class="panel-subtitle">Copy, visit, and remove short links from one compact table.</p>
            </div>
        </div>
    
        @if($shortUrls->isEmpty())
            <div class="surface-card text-center">
                <p>You haven't created any short URLs yet.</p>
            </div>
        @else
            <div class="table-shell">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Short Code</th>
                            <th>Original URL</th>
                            <th>Hits</th>
                            <th>Created At</th>
                            <th>Expires</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($shortUrls as $shortUrl)
                            <tr>
                                <td>
                                    <a href="{{ url("s/{$shortUrl->short_code}") }}">
                                        {{ $shortUrl->short_code }}
                                    </a>
                                </td>
                                <td class="break-all">
                                    <a href="{{ $shortUrl->url }}" target="_blank">
                                        {{ $shortUrl->url }}
                                    </a>
                                </td>
                                <td class="text-center">{{ $shortUrl->hits }}</td>
                                <td>{{ $shortUrl->created_at->format('Y-m-d H:i') }}</td>
                                <td class="text-center">
                                    @if($shortUrl->expires)
                                        <span class="status-pill status-pill--danger">{{ \Carbon\Carbon::parse($shortUrl->expires)->diffForHumans() }}</span>
                                    @else
                                        <span class="status-pill status-pill--muted">Never</span>
                                    @endif
                                </td>
                                <td>
                                    <div class="flex flex-wrap justify-center gap-2">
                                        <button class="clipboard btn-secondary btn-small" data-clipboard-text="{{ url("s/{$shortUrl->short_code}") }}">Copy</button>
                                        <a href="{{ url("s/{$shortUrl->short_code}") }}" class="btn-secondary btn-small">Visit</a>
                                        <form action="{{ url("s/{$shortUrl->short_code}?force=1&_back=1") }}" method="POST"
                                            onsubmit="return confirm('Are you sure you want to delete this short URL? This action cannot be undone.');"
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
        <pre class="codeblock">{
    "Version": "16.1.0",
    "Name": "Shup URL Shortener",
    "DestinationType": "URLShortener",
    "RequestMethod": "POST",
    "Headers": {
        "Authorization": "{{ $user->api_token }}"
    },
    "RequestURL": "{{ url("s") }}",
    "Body": "FormUrlEncoded",
    "Arguments": {
        "url": "{input}"
    },
    "URL": "{json:url}"
}
        </pre>
    </div>
</div>
@endsection
