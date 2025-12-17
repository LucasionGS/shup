<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invalid Upload Link</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-gray-100">
    <div class="flex justify-center items-center min-h-screen">
        <div class="bg-white shadow-lg rounded-lg p-8 max-w-md text-center">
            <div class="mb-4">
                <svg class="mx-auto h-16 w-16 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
            </div>
            <h1 class="text-2xl font-bold text-gray-800 mb-2">Invalid Upload Link</h1>
            <p class="text-gray-600 mb-4">
                This upload link is either invalid, has already been used, or has expired.
            </p>
            <p class="text-sm text-gray-500">
                Upload links can only be used once. Please contact the person who sent you this link.
            </p>
        </div>
    </div>
</body>
</html>
