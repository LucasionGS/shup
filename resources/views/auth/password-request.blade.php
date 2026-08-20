@extends('layouts.main')

@section('content')
<div class="app-panel app-panel--narrow auth-card">
    @include('partials.app-mark')
    <h2 class="text-2xl font-semibold mb-2">Reset Your Password</h2>
    <p class="panel-subtitle mb-6 text-center">Enter your email address and we'll send you a link to choose a new password.</p>

    @if (session('status'))
        <div class="alert-success mb-4" role="alert">{{ session('status') }}</div>
    @endif

    @if ($errors->any())
        <div class="alert-error mb-4" role="alert">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('password.email') }}" class="form-stack">
        @csrf
        <div>
            <label for="email" class="field-label">Email</label>
            <input type="email" id="email" name="email" value="{{ old('email') }}" required autofocus>
        </div>
        <button type="submit" class="btn-primary w-full">Send Reset Link</button>
    </form>

    <div class="mt-6 text-center text-sm">
        <a href="{{ route('login') }}">Back to login</a>
    </div>
</div>
@endsection
