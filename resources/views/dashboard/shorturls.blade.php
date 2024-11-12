@extends('layouts.main')

@section('content')

@php
    $user = auth()->user();
    $shortUrls = \App\Models\ShortURL::where('user_id', $user->id)->get();
@endphp

<div class="max-w-6xl mx-auto bg-white shadow-md rounded px-8 py-10">
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
                            <a href="{{ url("s/{$shortUrl->short_code}") }}" class="text-blue-600 hover:underline">Visit</a>
                            <form action="{{ url("s/{$shortUrl->short_code}?force=1&_back=1") }}" method="POST" class="inline">
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
