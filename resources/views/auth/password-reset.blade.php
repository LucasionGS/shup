@extends('layouts.main')

@section('content')
<div class="app-panel app-panel--narrow auth-card">
    @include('partials.app-mark')
    <h2 class="text-2xl font-semibold mb-2">Choose a New Password</h2>
    <p class="panel-subtitle mb-6 text-center">This link can only be used once.</p>

    @if ($errors->any())
        <div class="alert-error mb-4" role="alert">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('password.update') }}" class="form-stack">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">

        <div>
            <label for="email" class="field-label">Email</label>
            <input type="email" id="email" name="email" value="{{ old('email', $email) }}" required>
        </div>
        <div>
            <label for="password" class="field-label">New Password</label>
            <input type="password" id="password" name="password" minlength="8" required autofocus autocomplete="new-password">
        </div>
        <div>
            <label for="password_confirmation" class="field-label">Confirm Password</label>
            <input type="password" id="password_confirmation" name="password_confirmation" minlength="8" required autocomplete="new-password">
        </div>

        <button type="submit" class="btn-primary w-full">Reset Password</button>
    </form>

    <div class="mt-6 text-center text-sm">
        <a href="{{ route('login') }}">Back to login</a>
    </div>
</div>
@endsection
