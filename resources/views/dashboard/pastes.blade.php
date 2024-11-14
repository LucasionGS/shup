@extends('layouts.main')

@section('content')

@php
    $user = auth()->user();
    
    $users = \App\Models\File::where('user_id', $user->id)->get();
    $pastes = \App\Models\PasteBin::where('user_id', $user->id)->get();
@endphp

<div class="max-w-6xl mx-auto bg-white shadow-md rounded px-8 py-10">
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
                            <a href="{{ url("p/{$paste->short_code}") }}" class="text-blue-600 hover:underline">View</a>
                            <form action="{{ url("p/{$paste->short_code}?force=1&_back=1") }}" method="POST" class="inline">
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
    "Name": "s.ionnet.dev PasteBin Upload",
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
