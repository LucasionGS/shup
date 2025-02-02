@extends('layouts.main')

@section('content')

@php
    $user = auth()->user();
    
    $users = \App\Models\File::where('user_id', $user->id)->get();
    $pastes = \App\Models\PasteBin::where('user_id', $user->id)->get();
@endphp

<div class="max-w-6xl mx-auto bg-white shadow-md rounded px-8 py-10">
    <h1 class="text-3xl font-bold mb-6 text-center">Upload paste</h1>
    <form action="{{ url('p') }}?_back=1" method="POST" class="mt-6">
        @csrf
        <div class="flex flex-col md:flex-row">
            <textarea name="content" placeholder="Paste content" class="py-2 px-4 border rounded mb-2 md:mb-0 md:w-3/4"></textarea>
            <button type="submit" class="py-2 px-4 bg-blue-600 text-white rounded w-full md:w-1/4">Upload</button>
        </div>
    </form>
    @if (session('short_url'))
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mt-6" role="alert">
            <span class="block sm:inline">
                Paste uploaded: <a href="{{ session('short_url') }}" class="text-blue-600 hover:underline">{{ session('short_url') }}</a>
                
                <button class="clipboard" data-clipboard-text="{{ session('short_url') }}">📋</button>
            </span>
        </div>
    @endif

    <br>

    <h1 class="text-3xl font-bold mb-6 text-center">Your Paste Bins</h1>
    
    @if($pastes->isEmpty())
        <p class="text-gray-700 text-center">You haven't created any paste bins yet.</p>
    @else
        <table class="w-full table-auto border-collapse">
            <thead>
                <tr class="bg-gray-200 text-gray-700">
                    <th class="py-2 px-4 border">Short Code</th>
                    <th class="py-2 px-4 border">Created At</th>
                    <th class="py-2 px-4 border">Expires</th>
                    <th class="py-2 px-4 border">Password Protected</th>
                    <th class="py-2 px-4 border">Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach($pastes as $paste)
                    <tr class="text-gray-600">
                        <td class="py-2 px-4 border">{{ $paste->short_code }}</td>
                        <td class="py-2 px-4 border">{{ $paste->created_at->format('Y-m-d H:i') }}</td>
                        <td class="py-2 px-4 border text-center">
                            @if($paste->expires)
                                <span class="text-red-500">{{ \Carbon\Carbon::parse($paste->expires)->diffForHumans() }}</span>
                            @else
                                <span>N/A</span>
                            @endif
                        </td>
                        <td class="py-2 px-4 border text-center">
                            @if($paste->password)
                                <span class="text-red-500">Yes</span>
                            @else
                                <span>No</span>
                            @endif
                        </td>
                        <td class="py-2 px-4 border text-center">
                            <button class="clipboard" data-clipboard-text="{{ url("p/{$paste->short_code}") }}">📋</button>
                            <a href="{{ url("p/{$paste->short_code}") }}" class="text-blue-600 hover:underline">View</a>
                            <form action="{{ url("p/{$paste->short_code}?force=1&_back=1") }}" method="POST" class="inline"
                                onsubmit="return confirm('Are you sure you want to delete this paste bin? This action cannot be undone.');"
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
