@extends('layouts.main')

@section('content')

@php
    $user = auth()->user();
    
    $filesCount = \App\Models\File::where('user_id', $user->id)->get();
@endphp

<div class="max-w-6xl mx-auto bg-white shadow-md rounded px-8 py-10">
    <h1 class="text-3xl font-bold mb-6 text-center">Your Uploaded Files</h1>
    
    @if($filesCount->isEmpty())
        <p class="text-gray-700 text-center">You haven't uploaded any files yet.</p>
    @else
        <table class="w-full table-auto border-collapse">
            <thead>
                <tr class="bg-gray-200 text-gray-700">
                    <th class="py-2 px-4 border">File Name</th>
                    <th class="py-2 px-4 border">Extension</th>
                    <th class="py-2 px-4 border">MIME Type</th>
                    <th class="py-2 px-4 border">Downloads</th>
                    <th class="py-2 px-4 border"></th>
                    <th class="py-2 px-4 border"></th>
                    <th class="py-2 px-4 border">Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach($filesCount as $file)
                    <tr class="text-gray-600">
                        <td class="py-2 px-4 border">{{ $file->original_name }}</td>
                        <td class="py-2 px-4 border">{{ $file->ext }}</td>
                        <td class="py-2 px-4 border">{{ $file->mime }}</td>
                        <td class="py-2 px-4 border text-center">{{ $file->downloads }}</td>
                        <td class="py-2 px-4 border text-center">
                            @if($file->expires)
                                <span class="text-red-500">Expires: {{ Carbon\Carbon::parse($file->expires)->diffForHumans() }}</span>
                            @endif
                        </td>
                        <td class="py-2 px-4 border text-center">
                            @if($file->password)
                                <span class="text-red-500 ml-2">(Protected)</span>
                            @endif
                        </td>
                        <td class="py-2 px-4 border text-center">
                            @if (Auth::check() && str_starts_with($file->mime, "image/"))
                                <form action="{{ route("updateUserImage") }}" method="POST" class="inline">
                                    @csrf
                                    @method('POST')
                                    <input type="hidden" name="url" value="{{ "/f/$file->short_code" }}">
                                    <button type="submit" class="text-green-600 hover:underline ml-2">Set Profile Image</button>
                                </form>
                            @endif
                            <a href="{{ url("f/$file->short_code") }}" class="text-blue-600 hover:underline">Download</a>
                            <form action="{{ url("f/$file->short_code?force=1&_back=1") }}" method="POST" class="inline">
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
    "Name": "s.ionnet.dev File Upload",
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
