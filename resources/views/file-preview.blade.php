@php
    // The password travels with the media/download links so a protected file
    // stays viewable on this page without asking again.
    $query = $password ? ['password' => $password] : [];
    $base = url("/f/{$file->short_code}/" . rawurlencode($file->original_name));
    $downloadUrl = $base . ($query ? '?' . http_build_query($query) : '');
    $inlineUrl = $base . '?' . http_build_query($query + ['inline' => 1]);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $file->original_name }} | {{ App\Models\Configuration::appTitle() }}</title>
    @include('partials.app-icons')
    @vite(['resources/css/app.css', 'resources/js/app.ts'])
</head>
<body>
    <main class="public-shell">
        <div class="public-card form-stack" style="max-width: 56rem;">
            @include('partials.app-mark')

            <div class="text-center">
                <h1 class="text-2xl font-semibold break-all">{{ $file->original_name }}</h1>
                <p class="panel-subtitle">
                    {{ \App\Models\File::reduceFileSize($file->size) }}
                    @if($file->mime) &middot; {{ $file->mime }} @endif
                    @if($file->max_downloads)
                        &middot; {{ max(0, $file->max_downloads - $file->downloads) }} download(s) left
                    @endif
                </p>
            </div>

            <div class="file-preview-stage">
                @if($kind === 'image')
                    <img src="{{ $inlineUrl }}" alt="{{ $file->original_name }}" class="file-preview-media">
                @elseif($kind === 'video')
                    <video src="{{ $inlineUrl }}" class="file-preview-media" controls preload="metadata"></video>
                @elseif($kind === 'audio')
                    <audio src="{{ $inlineUrl }}" class="w-full" controls preload="metadata"></audio>
                @elseif($kind === 'pdf')
                    <iframe src="{{ $inlineUrl }}" class="file-preview-frame" title="{{ $file->original_name }}"></iframe>
                @elseif($kind === 'text' && $textPreview !== null)
                    {{-- Escaped by Blade: the file's contents are untrusted. --}}
                    <pre class="codeblock file-preview-text">{{ $textPreview }}</pre>
                @else
                    <div class="text-center">
                        <p class="muted-text">No preview available for this file type.</p>
                    </div>
                @endif
            </div>

            <div class="flex flex-col gap-2 sm:flex-row sm:justify-center">
                <a href="{{ $downloadUrl }}" class="btn-primary">Download</a>
                <button class="clipboard btn-secondary" data-clipboard-text="{{ url("/f/{$file->short_code}") }}">Copy Link</button>
            </div>
        </div>
    </main>
</body>
</html>
