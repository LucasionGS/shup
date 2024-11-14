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
    
    $fq = \App\Models\File::where('user_id', $user->id);
    
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

<div class="max-w-6xl mx-auto bg-white shadow-md rounded px-8 py-10">
    <h1 class="text-3xl font-bold mb-6 text-center">Your Uploaded Files</h1>
    
    @if($files->isEmpty() && empty($activeFilters["ext"]) && empty($activeFilters["mime"]))
        <p class="text-gray-700 text-center">You haven't uploaded any files yet.</p>
    @else
        <table class="w-full table-auto border-collapse">
            <thead>
                <tr class="bg-gray-200 text-gray-700">
                    <th class="py-2 px-4 border"></th>
                    <form action="{{ url()->current() }}" method="GET">
                        <th class="py-2 px-4 border">
                            <input
                            name="name"
                            type="text"
                            placeholder="File name"
                            class="w-full px-4 py-2 border rounded"
                            value="{{ $activeFilters["name"] }}"
                        >
                        </th>
                        <th class="py-2 px-4 border">
                            <select name="ext" onchange="this.form.submit()">
                                <option value="">Extension</option>
                                @foreach($filters["ext"] as $filter)
                                    <option value="{{ $filter->ext }}" @if($filter->ext == $activeFilters["ext"]) selected @endif>{{ $filter->ext }}</option>
                                @endforeach
                            </select>
                        </th>
                        <th class="py-2 px-4 border">
                            <select name="mime" onchange="this.form.submit()">
                                <option value="">MIME Type</option>
                                @foreach($filters["mime"] as $filter)
                                    <option value="{{ $filter->mime }}" @if($activeFilters["mime"] && str_starts_with($activeFilters["mime"], $filter->mime)) selected @endif>{{ $filter->mime }}</option>
                                @endforeach
                            </select>
                        </th>
                    </form>
                    <th class="py-2 px-4 border"></th>
                    <th class="py-2 px-4 border"></th>
                    <th class="py-2 px-4 border"></th>
                    <th class="py-2 px-4 border"></th>
                </tr>
                <tr class="bg-gray-200 text-gray-700">
                    <th class="py-2 px-4 border"></th>
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
                @foreach($files as $file)
                    <tr class="text-gray-600">
                        <td class="py-2 px-4 border w-40">
                            @if(str_starts_with($file->mime, "image/"))
                                <img src='{{"/f/$file->short_code"}}' class="block w-40 max-h-40 object-scale-down">
                            @endif
                        </td>
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

        <div class="mt-6 flex justify-center">
            @if($page > 0)
                <a href="{{ url()->current() . "?page=" . ($page - 1) . (empty($activeFilters["ext"]) ? "" : "&ext=" . $activeFilters["ext"]) . (empty($activeFilters["mime"]) ? "" : "&mime=" . $activeFilters["mime"]) }}" class="text-blue-600 hover:underline">Previous</a>
            @endif
            <span class="mx-4">Page {{ $page + 1 }}</span>
            @if($count > ($page + 1) * $pageSize)
                <a href="{{ url()->current() . "?page=" . ($page + 1) . (empty($activeFilters["ext"]) ? "" : "&ext=" . $activeFilters["ext"]) . (empty($activeFilters["mime"]) ? "" : "&mime=" . $activeFilters["mime"]) }}" class="text-blue-600 hover:underline">Next</a>
            @endif
        </div>
        <br>
        <hr>
    @endif

    <div class="mt-6 text-center">
        Use with ShareX
        @php
        $user = auth()->user();
        @endphp
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
