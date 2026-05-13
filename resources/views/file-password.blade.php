<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password | {{ App\Models\Configuration::appTitle() }}</title>
    @include('partials.app-icons')
    @vite(['resources/css/app.css', 'resources/js/app.ts'])
</head>
<body>
    <main class="public-shell">
        <form class="public-card form-stack" autocomplete="off">
            @include('partials.app-mark')
            <h1 class="text-2xl font-semibold text-center">Password Required</h1>
            <p class="panel-subtitle text-center">Enter the password to unlock this protected Shup item.</p>

            <div>
                <label for="pwd" class="field-label">Password</label>
                <input
                    autofocus
                    type="password" name="pwd" id="pwd"
                    autocomplete="off"
                    required
                >
            </div>

            @error('pwd')
                <div class="alert-error">{{ $message }}</div>
            @enderror

            <button class="btn-primary w-full" type="submit">Submit</button>
        </form>
    </main>
</body>
</html>