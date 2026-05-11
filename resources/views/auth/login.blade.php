@extends('layouts.main')

@section('content')
<div class="app-panel app-panel--narrow auth-card">
    <div class="public-brand">S</div>
    <h2 class="text-2xl font-semibold mb-2">Login to Your Account</h2>
    <p class="panel-subtitle mb-6 text-center">Access your file vault, short links, paste bins, and upload links.</p>

    <form method="POST" action="{{ route('login') }}" class="form-stack">
        @csrf
        <div>
            <label for="email" class="field-label">Email</label>
            <input type="email" id="email" name="email" required>
        </div>
        <div>
            <label for="password" class="field-label">Password</label>
            <input type="password" id="password" name="password" required>
        </div>
        <div class="flex items-center gap-2">
            <input type="checkbox" id="remember" name="remember">
            <label for="remember" class="mb-0">Remember me</label>
        </div>
        <button type="submit" class="btn-primary w-full">Login</button>
    </form>

    <div class="mt-6 text-center text-sm">
        <a href="{{ route('register') }}">Don't have an account? Register</a>
    </div>
    <div class="mt-3 text-center text-sm">
        <a href="{{ route('password.request') }}">Forgot your password?</a>
    </div>
</div>
@endsection
