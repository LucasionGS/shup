@extends('layouts.main')

@php
use App\Models\InvitedUsers;
$token = request()->query('invite');
if (isset($token)) {
    $invited_user = InvitedUsers::where('token', $token)
        ->where('expires_at', '>', now())
        ->first();
}
@endphp

@section('content')
<div class="max-w-md mx-auto bg-white shadow-md rounded px-8 py-6">
    <h2 class="text-2xl font-bold mb-6 text-center">Create an Account</h2>
    @if($errors->any())
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
            <strong class="font-bold">Whoops!</strong>
            <span class="block sm:inline">{{ $errors->first() }}</span>
        </div>
    @endif
    <form method="POST" action="{{ route('register') }}">
        @csrf
        <!-- Name Input -->
        <div class="mb-4">
            <label for="name" class="block text-gray-700 font-bold mb-2">Name</label>
            <input type="text" id="name" name="name" class="w-full border rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-600" required>
        </div>
        <!-- Email Input -->
        <div class="mb-4">
            <label for="email" class="block text-gray-700 font-bold mb-2">Email</label>
            <input
                type="email" id="email" name="email" class="w-full border rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-600" required
                @isset($invited_user)
                    value="{{ $invited_user->email }}"
                    readonly
                @endisset
            >
        </div>

        @isset($invited_user)
            <input type="hidden" name="invite" value="{{ $token }}">
        @endisset
        
        <!-- Password Input -->
        <div class="mb-4">
            <label for="password" class="block text-gray-700 font-bold mb-2">Password</label>
            <input type="password" id="password" name="password" class="w-full border rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-600" required>
        </div>
        <!-- Confirm Password Input -->
        <div class="mb-6">
            <label for="password_confirmation" class="block text-gray-700 font-bold mb-2">Confirm Password</label>
            <input type="password" id="password_confirmation" name="password_confirmation" class="w-full border rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-600" required>
        </div>
        <!-- Submit Button -->
        <div class="text-center">
            <button type="submit" class="w-full bg-blue-600 text-white font-bold py-2 rounded hover:bg-blue-700 transition duration-200">Register</button>
        </div>
    </form>
    <!-- Additional Links -->
    <div class="mt-6 text-center">
        <a href="{{ route('login') }}" class="text-blue-600 hover:underline">Already have an account? Login</a>
    </div>
</div>
@endsection
