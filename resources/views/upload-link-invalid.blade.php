<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invalid Upload Link</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <main class="public-shell">
        <div class="public-card text-center">
            <div class="public-brand" style="color: var(--danger); background: rgba(255, 107, 107, 0.16); border: 1px solid rgba(255, 107, 107, 0.3);">!</div>
            <h1 class="text-2xl font-semibold mb-2">Invalid Upload Link</h1>
            <p class="panel-subtitle mb-4 text-center">
                This upload link is either invalid, has already been used, or has expired.
            </p>
            <p class="helper-text">
                Upload links can only be used once. Please contact the person who sent you this link.
            </p>
        </div>
    </main>
</body>
</html>
