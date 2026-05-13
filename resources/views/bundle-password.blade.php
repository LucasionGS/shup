<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Required</title>
    @include('partials.app-icons')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <main class="public-shell">
        <form class="public-card form-stack" autocomplete="off">
            @include('partials.app-mark')
            <h1 class="text-2xl font-semibold text-center">Password Required</h1>
            <p class="panel-subtitle text-center">{{ $bundle->name }}</p>

            <div>
                <label for="pwd" class="field-label">Password</label>
                <input autofocus type="password" name="pwd" id="pwd" autocomplete="off" required>
            </div>

            <button class="btn-primary w-full" type="submit">Open Bundle</button>
        </form>
    </main>
</body>
</html>