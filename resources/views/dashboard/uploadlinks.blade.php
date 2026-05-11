@extends('layouts.main')

@section('content')

@php
    $user = auth()->user();
    
    $links = \App\Models\UploadLink::where('user_id', $user->id)
        ->orderBy('created_at', 'desc')
        ->get();
@endphp

<div class="app-panel">
    <div class="panel-header">
        <div>
            <div class="panel-eyebrow">Guest intake</div>
            <h1 class="panel-title">Generate Upload Link</h1>
            <p class="panel-subtitle">Create a one-time link that lets someone upload a file directly into your account.</p>
        </div>
    </div>
    
    <form action="{{ url('ul') }}?_back=1" method="POST" class="surface-card">
        @csrf
        <div class="flex flex-col md:flex-row gap-4">
            <div class="flex-1">
                <label for="expires" class="field-label">Expiration (minutes, optional)</label>
                <input 
                    type="number" 
                    name="expires" 
                    id="expires"
                    min="0"
                    placeholder="Leave empty for no expiration"
                >
                <p class="helper-text">Link will expire after this many minutes or after one upload.</p>
            </div>
            <div class="flex items-end">
                <button 
                    type="submit" 
                    class="btn-primary w-full md:w-auto"
                >
                    Generate Link
                </button>
            </div>
        </div>
    </form>

    @if (session('upload_link'))
        <div class="alert-success mt-6" role="alert">
            <div class="font-semibold">Upload link created</div>
            <div class="flex items-center gap-2 mt-2">
                <input 
                    type="text" 
                    value="{{ session('upload_link') }}" 
                    readonly 
                    class="flex-1"
                >
                <button 
                    class="clipboard btn-secondary" 
                    data-clipboard-text="{{ session('upload_link') }}"
                >
                    Copy
                </button>
            </div>
        </div>
    @endif

    <div class="my-8 border-t border-white/10"></div>

    <div class="panel-header">
        <div>
            <div class="panel-eyebrow">One-time links</div>
            <h2>Your Upload Links</h2>
            <p class="panel-subtitle">Track active, used, and expired intake links.</p>
        </div>
    </div>
    
    @if($links->isEmpty())
        <div class="surface-card text-center">
            <p>You haven't created any upload links yet.</p>
        </div>
    @else
        <div class="table-shell">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Link</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Expires</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($links as $link)
                        <tr class="{{ $link->isValid() ? '' : 'opacity-60' }}">
                            <td>
                                <div class="flex flex-wrap items-center gap-2">
                                    <code class="rounded-md border border-white/10 bg-black/20 px-2 py-1 text-sm break-all">
                                        {{ url('/ul/' . $link->short_code) }}
                                    </code>
                                    @if($link->isValid())
                                        <button 
                                            class="clipboard btn-secondary btn-small" 
                                            data-clipboard-text="{{ url('/ul/' . $link->short_code) }}"
                                            title="Copy to clipboard"
                                        >
                                            Copy
                                        </button>
                                    @endif
                                </div>
                            </td>
                            <td>
                                @if($link->used)
                                    <span class="status-pill status-pill--muted">Used</span>
                                @elseif($link->expires && $link->expires->isPast())
                                    <span class="status-pill status-pill--danger">Expired</span>
                                @else
                                    <span class="status-pill status-pill--active">Active</span>
                                @endif
                            </td>
                            <td class="text-sm">
                                {{ $link->created_at->diffForHumans() }}
                            </td>
                            <td class="text-sm">
                                @if($link->expires)
                                    {{ $link->expires->diffForHumans() }}
                                @else
                                    <span class="muted-text">Never</span>
                                @endif
                            </td>
                            <td>
                                <div class="flex flex-wrap justify-center gap-2">
                                    @if($link->isValid())
                                        <a 
                                            href="{{ url('/ul/' . $link->short_code) }}" 
                                            target="_blank"
                                            class="btn-secondary btn-small"
                                        >
                                            View
                                        </a>
                                    @endif
                                    <form 
                                        action="{{ url('/ul/' . $link->short_code) }}?_back=1" 
                                        method="POST"
                                        onsubmit="return confirm('Delete this upload link?');"
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

@endsection
