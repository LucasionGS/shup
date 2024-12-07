@extends('layouts.main')

@section('content')

@php
    $user = auth()->user();
    $shortUrls = \App\Models\ShortURL::where('user_id', $user->id)->get();
@endphp

<div class="max-w-6xl mx-auto bg-white shadow-md rounded px-8 py-10">
    <h1 class="text-3xl font-bold mb-6 text-center">Create Short URL</h1>
    <form action="{{ url('s') }}?_back=1" method="POST" class="mt-6">
        @csrf
        <div class="flex flex-col md:flex-row">
            <input type="text" name="url" placeholder="URL to shorten" class="py-2 px-4 border rounded mb-2 md:mb-0 md:w-3/4">
            <button type="submit" class="py-2 px-4 bg-blue-600 text-white rounded w-full md:w-1/4">Shorten</button>
        </div>
    </form>
    @if (session('short_url'))
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mt-6" role="alert">
            <span class="block sm:inline">
                Short URL created: <a href="{{ session('short_url') }}" class="text-blue-600 hover:underline">{{ session('short_url') }}</a>
                
                <button class="clipboard" data-clipboard-text="{{ session('short_url') }}">📋</button>
            </span>
        </div>
    @endif
    
    <br>

    <h1 class="text-3xl font-bold mb-6 text-center">Your Shortened URLs</h1>
    
    @if($shortUrls->isEmpty())
        <p class="text-gray-700 text-center">You haven't created any short URLs yet.</p>
    @else
        <table class="w-full table-auto border-collapse">
            <thead>
                <tr class="bg-gray-200 text-gray-700">
                    <th class="py-2 px-4 border">Short Code</th>
                    <th class="py-2 px-4 border">Original URL</th>
                    <th class="py-2 px-4 border">Hits</th>
                    <th class="py-2 px-4 border">Created At</th>
                    <th class="py-2 px-4 border">Expires</th>
                    <th class="py-2 px-4 border">Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach($shortUrls as $shortUrl)
                    <tr class="text-gray-600">
                        <td class="py-2 px-4 border">
                            <a href="{{ url("s/{$shortUrl->short_code}") }}" class="text-blue-600 hover:underline">
                                {{ $shortUrl->short_code }}
                            </a>
                        </td>
                        <td class="py-2 px-4 border break-all">
                            <a href="{{ $shortUrl->url }}" target="_blank" class="text-blue-600 hover:underline">
                                {{ $shortUrl->url }}
                            </a>
                        </td>
                        <td class="py-2 px-4 border text-center">{{ $shortUrl->hits }}</td>
                        <td class="py-2 px-4 border">{{ $shortUrl->created_at->format('Y-m-d H:i') }}</td>
                        <td class="py-2 px-4 border text-center">
                            @if($shortUrl->expires)
                                <span class="text-red-500">{{ \Carbon\Carbon::parse($shortUrl->expires)->diffForHumans() }}</span>
                            @else
                                <span>N/A</span>
                            @endif
                        </td>
                        <td class="py-2 px-4 border text-center">
                            <button class="clipboard" data-clipboard-text="{{ url("s/{$shortUrl->short_code}") }}">📋</button>
                            <a href="{{ url("s/{$shortUrl->short_code}") }}" class="text-blue-600 hover:underline">Visit</a>
                            <form action="{{ url("s/{$shortUrl->short_code}?force=1&_back=1") }}" method="POST" class="inline"
                                onsubmit="return confirm('Are you sure you want to delete this short URL? This action cannot be undone.');"
                            >
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-red-600 hover:underline ml-2">Delete</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <div class="mt-6 text-center">
        Use with ShareX
        <pre class="codeblock">{
    "Version": "16.1.0",
    "Name": "s.ionnet.dev URL Shortener",
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
