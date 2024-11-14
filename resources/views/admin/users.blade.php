@extends('layouts.main')

@section('content')

@php
    $user = auth()->user();
    
    $users = \App\Models\User::all();
@endphp

<div class="max-w-6xl mx-auto bg-white shadow-md rounded px-8 py-10">
    <h1 class="text-3xl font-bold mb-6 text-center">User settings</h1>

    <form action="{{ route("configure") }}" method="POST">
        @csrf
        @method('POST')
        <h2 class="text-xl font-bold mb-2">General</h2>
        <label for="allow_signups" class="block text-gray-700 font-bold mb-2">Allow Signups</label>
        @include('form-inputs.select-bool', [
            'name' => 'allow_signup', 'value' => App\Models\Configuration::getBool('allow_signup', false)
        ])

        <br>
        <button type="submit" class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded mt-4">Save</button>
    </form>

    <hr>
    
    <h2 class="text-xl font-bold mb-2">Users</h2>

    @if (session('invite_info'))
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative" role="alert">
            <span class="block sm:inline">{{ session('invite_info') }}</span>
        </div>
    @endif
    <form action="{{ route("inviteUser") }}?_back=1" method="POST">
        @csrf
        @method('POST')

        <label for="email" class="block text-gray-700 font-bold">Invite User</label>
        <input type="email" name="email" placeholder="Email" class="border rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-600">
        <button type="submit" class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded mt-1">Invite User</button>
    </form>
    
    <table class="w-full table-auto border-collapse">
        <thead>
            <tr class="bg-gray-200 text-gray-700">
                <th class="py-2 px-4 border">ID</th>
                <th class="py-2 px-4 border">Name</th>
                <th class="py-2 px-4 border">Email</th>
                <th class="py-2 px-4 border">Role</th>
                <th class="py-2 px-4 border"></th>
                <th class="py-2 px-4 border"></th>
            </tr>
        </thead>
        <tbody>
            @foreach($users as $user)
                <tr class="text-gray-600">
                    <form action="{{ url("user/$user->id?_back=1") }}" method="POST">
                        @csrf
                        @method('PUT')

                        <!-- Unnecessary -->
                        <!-- <input type="hidden" name="id" value="{{ $user->id }}"> -->

                        <td class="py-2 px-4 border">{{ $user->id }}</td>
                        <td class="py-2 px-4 border">
                            <input name="name" value="{{ $user->name }}">
                        </td>
                        <td class="py-2 px-4 border">
                            <input name="email" value="{{ $user->email }}">
                        </td>
                        <td class="py-2 px-4 border text-center">
                            @if ($user->id !== auth()->id())
                                <select name="role" id="role" class="w-full border rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-600">
                                    @foreach(\App\Models\User::$roles as $key => $role)
                                        <option value="{{ $key }}" @if($user->role === $key) selected @endif>{{ $role }}</option>
                                    @endforeach
                                </select>
                            @else
                                <span title="Cannot update your own role">{{ $user->getRoleName() }}</span>
                            @endif
                        </td>
                        <td class="py-2 px-4 border text-center">
                            <button type="submit" class="text-green-600 hover:underline ml-2">Save</button>
                        </td>
                    </form>
                    <td class="py-2 px-4 border text-center">
                        <form action="{{ url("user/$user->id?_back=1") }}" method="POST" class="inline">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="text-red-600 hover:underline ml-2">Delete</button>
                        </form>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endsection
