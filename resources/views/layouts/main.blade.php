<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? env("APP_NAME") }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-gray-100 font-sans leading-normal tracking-normal">

    <!-- Header -->
    <header class="header">
        <div class="container-standard flex justify-between items-center">
            <a href="{{ url('/') }}" class="header-logo">
                {{ env("APP_NAME") }}
            </a>
            <!-- <nav class="space-x-4">
                <a href="{{ url('/file-uploader') }}" class="nav-link">File Uploader</a>
                <a href="{{ url('/url-shortener') }}" class="nav-link">URL Shortener</a>
                <a href="{{ url('/paste-bin') }}" class="nav-link">Paste Bin</a>
            </nav> -->
            <div>
                @auth
                    <a href="{{ url('/dashboard') }}" class="auth-button">Dashboard</a>
                    <a href="{{ route('logout') }}" class="logout-button">Logout</a>
                @else
                    <a href="{{ route('login') }}" class="auth-button">Login</a>
                @endauth
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="container-standard main-content">
        @yield('content')
    </main>

    <!-- Footer -->
    <footer class="footer">
        Powered by {{ env("APP_NAME") }}
    </footer>
</body>
</html>
