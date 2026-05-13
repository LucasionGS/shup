@extends('layouts.main')

@section('content')

@php
    $users = \App\Models\User::all();
@endphp

<div class="app-panel">
    <div class="panel-header">
        <div>
            <div class="panel-eyebrow">Admin console</div>
            <h1 class="panel-title">User Settings</h1>
            <p class="panel-subtitle">Manage signup access, anonymous uploads, invitations, and user roles.</p>
        </div>
    </div>

    <form action="{{ route("configure") }}" method="POST" class="surface-card">
        @csrf
        @method('POST')
        <h2 class="mb-4">General</h2>
        <div class="form-grid">
            <div>
                <label for="app_title" class="field-label">App Title</label>
                <input type="text" id="app_title" name="app_title" maxlength="80" value="{{ App\Models\Configuration::appTitle() }}" required>
            </div>
            <div>
                <label for="allow_signup" class="field-label">Allow Signups</label>
                @include('form-inputs.select-bool', [
                    'name' => 'allow_signup', 'id' => 'allow_signup', 'value' => App\Models\Configuration::getBool('allow_signup', false)
                ])
            </div>
            <div>
                <label for="allow_anonymous_upload" class="field-label">Allow Anonymous Uploads</label>
                @include('form-inputs.select-bool', [
                    'name' => 'allow_anonymous_upload', 'value' => App\Models\Configuration::getBool('allow_anonymous_upload', false)
                ])
            </div>
        </div>
        <button type="submit" class="btn-primary mt-4">Save Settings</button>
    </form>

    <div class="my-8 border-t border-white/10"></div>
    
    <div class="panel-header">
        <div>
            <div class="panel-eyebrow">Accounts</div>
            <h2>Users</h2>
            <p class="panel-subtitle">Invite new users and update account details.</p>
        </div>
    </div>

    @if (session('invite_info'))
        <div class="alert-success mb-4" role="alert">
            {{ session('invite_info') }}
        </div>
    @endif

    <form action="{{ route("inviteUser") }}?_back=1" method="POST" class="surface-card mb-6">
        @csrf
        @method('POST')

        <label for="email" class="field-label">Invite User</label>
        <div class="form-row">
            <input type="email" id="email" name="email" placeholder="Email" required>
            <button type="submit" class="btn-primary">Invite User</button>
        </div>
    </form>
    
    <div class="table-shell">
        <table class="data-table min-w-[920px]">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th class="text-center">Save</th>
                    <th class="text-center">Delete</th>
                </tr>
            </thead>
            <tbody>
                @foreach($users as $listedUser)
                    <tr>
                        <form action="{{ url("user/$listedUser->id?_back=1") }}" method="POST">
                            @csrf
                            @method('PUT')

                            <td>{{ $listedUser->id }}</td>
                            <td>
                                <input name="name" value="{{ $listedUser->name }}">
                            </td>
                            <td>
                                <input name="email" value="{{ $listedUser->email }}">
                            </td>
                            <td class="text-center">
                                @if ($listedUser->id !== auth()->id())
                                    <select name="role" id="role-{{ $listedUser->id }}">
                                        @foreach(\App\Models\User::$roles as $key => $role)
                                            <option value="{{ $key }}" @if($listedUser->role === $key) selected @endif>{{ $role }}</option>
                                        @endforeach
                                    </select>
                                @else
                                    <span class="status-pill status-pill--muted" title="Cannot update your own role">{{ $listedUser->getRoleName() }}</span>
                                @endif
                            </td>
                            <td class="text-center">
                                <button type="submit" class="btn-secondary btn-small">Save</button>
                            </td>
                        </form>
                        <td class="text-center">
                            <form action="{{ url("user/$listedUser->id?_back=1") }}" method="POST" onsubmit="return confirm('Delete this user?');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn-danger btn-small">Delete</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection
