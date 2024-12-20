@extends('layouts.main')

@section('content')
<div class="max-w-md mx-auto bg-white shadow-md rounded px-8 py-6">
    <h2 class="text-2xl font-bold mb-6 text-center">Login to Your Account</h2>
    <form method="POST" action="{{ route('login') }}">
        @csrf
        <!-- Email Input -->
        <div class="mb-4">
            <label for="email" class="block text-gray-700 font-bold mb-2">Email</label>
            <input type="email" id="email" name="email" class="w-full border rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-600" required>
        </div>
        <!-- Password Input -->
        <div class="mb-6">
            <label for="password" class="block text-gray-700 font-bold mb-2">Password</label>
            <input type="password" id="password" name="password" class="w-full border rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-600" required>
        </div>
        <!-- Remember Me -->
        <div class="mb-4 flex items-center">
            <input type="checkbox" id="remember" name="remember" class="mr-2">
            <label for="remember" class="text-gray-700">Remember me</label>
        </div>
        <!-- Submit Button -->
        <div class="text-center">
            <button type="submit" class="w-full bg-blue-600 text-white font-bold py-2 rounded hover:bg-blue-700 transition duration-200">Login</button>
        </div>
    </form>
    <!-- Additional Links -->
    <div class="mt-6 text-center">
        <a href="{{ route('register') }}" class="text-blue-600 hover:underline">Don't have an account? Register</a>
    </div>
    <div class="mt-6 text-center">
        <a href="{{ route('password.request') }}" class="text-blue-600 hover:underline">Forgot your password?</a>
    </div>
</div>
@endsection
