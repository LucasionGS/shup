@extends('layouts.main')

@section('content')

@php
    $user = auth()->user();
    
    $links = \App\Models\UploadLink::where('user_id', $user->id)
        ->orderBy('created_at', 'desc')
        ->get();
@endphp

<div class="max-w-6xl mx-auto bg-white shadow-md rounded px-8 py-10">
    <h1 class="text-3xl font-bold mb-6 text-center">Generate Upload Link</h1>
    <p class="text-gray-600 mb-6 text-center">
        Create a one-time use link that allows someone to upload a file to your account.
    </p>
    
    <form action="{{ url('ul') }}?_back=1" method="POST" class="mt-6">
        @csrf
        <div class="flex flex-col md:flex-row gap-4">
            <div class="flex-1">
                <label for="expires" class="block text-sm font-medium text-gray-700 mb-2">
                    Expiration (minutes, optional)
                </label>
                <input 
                    type="number" 
                    name="expires" 
                    id="expires"
                    min="0"
                    placeholder="Leave empty for no expiration"
                    class="w-full py-2 px-4 border rounded focus:ring-2 focus:ring-blue-500"
                >
                <p class="text-xs text-gray-500 mt-1">Link will expire after this many minutes or after one upload</p>
            </div>
            <div class="flex items-end">
                <button 
                    type="submit" 
                    class="py-2 px-6 bg-blue-600 hover:bg-blue-700 text-white rounded w-full md:w-auto transition duration-200"
                >
                    Generate Link
                </button>
            </div>
        </div>
    </form>

    @if (session('upload_link'))
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mt-6" role="alert">
            <span class="block sm:inline font-semibold mb-2">Upload link created!</span>
            <div class="flex items-center gap-2 mt-2">
                <input 
                    type="text" 
                    value="{{ session('upload_link') }}" 
                    readonly 
                    class="flex-1 py-2 px-4 border rounded bg-white"
                >
                <button 
                    class="clipboard py-2 px-4 bg-blue-500 hover:bg-blue-600 text-white rounded transition duration-200" 
                    data-clipboard-text="{{ session('upload_link') }}"
                >
                    📋 Copy
                </button>
            </div>
        </div>
    @endif

    <hr class="my-8">

    <h2 class="text-2xl font-bold mb-6 text-center">Your Upload Links</h2>
    
    @if($links->isEmpty())
        <p class="text-gray-700 text-center">You haven't created any upload links yet.</p>
    @else
        <div class="overflow-x-auto">
            <table class="w-full table-auto border-collapse">
                <thead>
                    <tr class="bg-gray-200 text-gray-700">
                        <th class="py-2 px-4 border text-left">Link</th>
                        <th class="py-2 px-4 border text-left">Status</th>
                        <th class="py-2 px-4 border text-left">Created</th>
                        <th class="py-2 px-4 border text-left">Expires</th>
                        <th class="py-2 px-4 border text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($links as $link)
                        <tr class="hover:bg-gray-50 {{ $link->isValid() ? '' : 'opacity-50' }}">
                            <td class="py-2 px-4 border">
                                <div class="flex items-center gap-2">
                                    <code class="bg-gray-100 px-2 py-1 rounded text-sm">
                                        {{ url('/ul/' . $link->short_code) }}
                                    </code>
                                    @if($link->isValid())
                                        <button 
                                            class="clipboard text-blue-500 hover:text-blue-700" 
                                            data-clipboard-text="{{ url('/ul/' . $link->short_code) }}"
                                            title="Copy to clipboard"
                                        >
                                            📋
                                        </button>
                                    @endif
                                </div>
                            </td>
                            <td class="py-2 px-4 border">
                                @if($link->used)
                                    <span class="inline-block bg-gray-500 text-white text-xs px-2 py-1 rounded">Used</span>
                                @elseif($link->expires && $link->expires->isPast())
                                    <span class="inline-block bg-red-500 text-white text-xs px-2 py-1 rounded">Expired</span>
                                @else
                                    <span class="inline-block bg-green-500 text-white text-xs px-2 py-1 rounded">Active</span>
                                @endif
                            </td>
                            <td class="py-2 px-4 border text-sm">
                                {{ $link->created_at->diffForHumans() }}
                            </td>
                            <td class="py-2 px-4 border text-sm">
                                @if($link->expires)
                                    {{ $link->expires->diffForHumans() }}
                                @else
                                    <span class="text-gray-400">Never</span>
                                @endif
                            </td>
                            <td class="py-2 px-4 border text-center">
                                @if($link->isValid())
                                    <a 
                                        href="{{ url('/ul/' . $link->short_code) }}" 
                                        target="_blank"
                                        class="text-blue-600 hover:underline mr-2"
                                    >
                                        View
                                    </a>
                                @endif
                                <a 
                                    href="{{ url('/ul/' . $link->short_code) }}?_back=1" 
                                    onclick="event.preventDefault(); if(confirm('Delete this upload link?')) { document.getElementById('delete-form-{{ $link->id }}').submit(); }"
                                    class="text-red-600 hover:underline"
                                >
                                    Delete
                                </a>
                                <form 
                                    id="delete-form-{{ $link->id }}" 
                                    action="{{ url('/ul/' . $link->short_code) }}?_back=1" 
                                    method="POST" 
                                    class="hidden"
                                >
                                    @csrf
                                    @method('DELETE')
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

@endsection
