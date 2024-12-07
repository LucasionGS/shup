<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <div class="flex justify-center mt-4">
        <form class="flex flex-col p-2 bg-gray-200 rounded-lg" autocomplete="off">
            <label for="password">Password</label>
            <input
                autofocus
                class="border border-gray-300 p-2"
                type="password" name="pwd" id="pwd"
                autocomplete="off"
                required
            >
            @error('pwd')
                <div class="text-red-500">{{ $message }}</div>
            @enderror
            <button
                class="bg-blue-500 text-white p-2 mt-2 rounded-lg"
                type="submit"
            >Submit</button>
        </form>
    </div>
</body>
</html>