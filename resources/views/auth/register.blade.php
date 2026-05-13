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
<div class="app-panel app-panel--narrow auth-card">
    @include('partials.app-mark')
    <h2 class="text-2xl font-semibold mb-2">Create an Account</h2>
    <p class="panel-subtitle mb-6 text-center">Set up your Shup vault for files, links, and paste bins.</p>

    @if($errors->any())
        <div class="alert-error mb-4" role="alert">
            <strong class="font-bold">Whoops!</strong>
            <span class="block sm:inline">{{ $errors->first() }}</span>
        </div>
    @endif

    <form method="POST" action="{{ route('register') }}" class="form-stack">
        @csrf
        <div>
            <label for="name" class="field-label">Name</label>
            <input type="text" id="name" name="name" required>
        </div>
        <div>
            <label for="email" class="field-label">Email</label>
            <input
                type="email" id="email" name="email" required
                @isset($invited_user)
                    value="{{ $invited_user->email }}"
                    readonly
                @endisset
            >
        </div>

        @isset($invited_user)
            <input type="hidden" name="invite" value="{{ $token }}">
        @endisset
        
        <div>
            <label for="password" class="field-label">Password</label>
            <input type="password" id="password" name="password" required>
        </div>
        <div>
            <label for="password_confirmation" class="field-label">Confirm Password</label>
            <input type="password" id="password_confirmation" name="password_confirmation" required>
        </div>
        <button type="submit" class="btn-primary w-full">Register</button>
    </form>

    <div class="mt-6 text-center text-sm">
        <a href="{{ route('login') }}">Already have an account? Login</a>
    </div>
</div>
@endsection
