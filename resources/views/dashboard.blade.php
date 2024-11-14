@extends('layouts.main')

@section('content')

@php
    $user = auth()->user();
    $users = \App\Models\File::where('user_id', $user->id)->count();
    $urlsCount = \App\Models\ShortURL::where('user_id', $user->id)->count();
    $pasteBinsCount = \App\Models\PasteBin::where('user_id', $user->id)->count();
    
    function reduceFileSize($bytes) {
        $bytes = (int) $bytes;
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $unit = 0;
        while ($bytes >= 1024 && $unit < count($units) - 1) {
            $bytes /= 1024;
            $unit++;
        }
        return round($bytes, 2) . ' ' . $units[$unit];
    }
@endphp

<div class="max-w-6xl mx-auto bg-white shadow-md rounded px-8 py-10">
    <h1 class="text-3xl font-bold mb-6 text-center">Your Dashboard</h1>
    <p>
        Welcome back, {{ $user->name }}!
        <br>
        You have used {{ reduceFileSize($user->storage_used) }} / {{ $user->storage_limit === 0 ? "∞" : reduceFileSize($user->storage_limit) }} of your storage.
        <br>
        <br>
        Need to reset your API key?
        <a href="{{ route('resetapi') }}" class="text-blue-600 hover:underline">Reset API Key here</a>.
    </p>
    <div class="grid grid-cols-3 gap-4">
        <div class="bg-gray-200 p-4 rounded">
            <h2 class="text-xl font-bold mb-2">Your Uploaded Files</h2>
            <p class="text-gray-700">You have uploaded {{ $users }} files.</p>
            <a href="{{ route('files') }}" class="text-blue-600 hover:underline">View Files</a>
        </div>
        <div class="bg-gray-200 p-4 rounded">
            <h2 class="text-xl font-bold mb-2">Your Shortened URLs</h2>
            <p class="text-gray-700">You have shortened {{ $urlsCount }} URLs.</p>
            <a href="{{ route('shorturls') }}" class="text-blue-600 hover:underline">View URLs</a>
        </div>
        <div class="bg-gray-200 p-4 rounded">
            <h2 class="text-xl font-bold mb-2">Your Paste Bins</h2>
            <p class="text-gray-700">You have created {{ $pasteBinsCount }} paste bins.</p>
            <a href="{{ route('pastes') }}" class="text-blue-600 hover:underline">View Paste Bins</a>
        </div>
    </div>
</div>
@endsection
