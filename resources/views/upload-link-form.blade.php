<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload File</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <main class="public-shell">
        <div class="public-card">
                <div class="public-brand">S</div>
                <h1 class="text-2xl font-semibold mb-2 text-center">Upload Your File</h1>
                <p class="panel-subtitle mb-6 text-center">Send a file securely through this one-time Shup upload link.</p>
                
                @if(session('file_url'))
                    <div class="alert-success mb-4">
                        <p class="font-semibold mb-2">File uploaded successfully!</p>
                        <div class="flex items-center gap-2">
                            <input 
                                type="text" 
                                value="{{ session('file_url') }}" 
                                readonly 
                                class="flex-1"
                            >
                            <button 
                                data-clipboard-text="{{ session('file_url') }}"
                                class="clipboard btn-secondary"
                            >
                                Copy
                            </button>
                        </div>
                        <p class="helper-text">This upload link can no longer be used.</p>
                    </div>
                @else
                    <div id="progress-container" class="hidden mb-4">
                        <div class="flex justify-between items-center mb-2">
                            <span class="text-sm font-medium">Uploading...</span>
                            <span id="progress-percent" class="text-sm font-medium" style="color: var(--accent);">0%</span>
                        </div>
                        <div class="progress-track h-3 overflow-hidden rounded-md">
                            <div 
                                id="progress-bar" 
                                class="progress-bar h-3 rounded-md transition-all duration-300"
                                style="width: 0%"
                            ></div>
                        </div>
                        <p class="helper-text" id="progress-status">Preparing upload...</p>
                    </div>

                    <form 
                        id="upload-form"
                        action="/ul/{{ $link->short_code }}?_back=1" 
                        method="POST" 
                        enctype="multipart/form-data"
                        class="space-y-4"
                    >
                        @csrf
                        
                        <div>
                            <label for="file" class="field-label">Select File</label>
                            <input 
                                type="file" 
                                name="file" 
                                id="file" 
                                required
                            >
                            @error('file')
                                <div class="text-red-500 text-sm mt-1">{{ $message }}</div>
                            @enderror
                        </div>

                        <div>
                            <label for="password" class="field-label">Password (optional)</label>
                            <input 
                                type="password" 
                                name="password" 
                                id="password"
                                placeholder="Leave empty for no password"
                            >
                            <p class="helper-text">
                                If set, the file will be encrypted and require this password to access.
                            </p>
                            @error('password')
                                <div class="text-red-500 text-sm mt-1">{{ $message }}</div>
                            @enderror
                        </div>

                        @if($errors->has('error'))
                            <div class="alert-error">
                                {{ $errors->first('error') }}
                            </div>
                        @endif

                        <button 
                            type="submit"
                            id="submit-btn"
                            class="btn-primary w-full"
                        >
                            Upload File
                        </button>
                    </form>

                    <script>
                        document.getElementById('upload-form').addEventListener('submit', function(e) {
                            e.preventDefault();
                            
                            const form = e.target;
                            const formData = new FormData(form);
                            const progressContainer = document.getElementById('progress-container');
                            const progressBar = document.getElementById('progress-bar');
                            const progressPercent = document.getElementById('progress-percent');
                            const progressStatus = document.getElementById('progress-status');
                            const submitBtn = document.getElementById('submit-btn');
                            
                            // Show progress bar and disable submit button
                            progressContainer.classList.remove('hidden');
                            submitBtn.disabled = true;
                            submitBtn.textContent = 'Uploading...';
                            
                            const xhr = new XMLHttpRequest();
                            
                            // Track upload progress
                            xhr.upload.addEventListener('progress', function(e) {
                                if (e.lengthComputable) {
                                    const percentComplete = Math.round((e.loaded / e.total) * 100);
                                    progressBar.style.width = percentComplete + '%';
                                    progressPercent.textContent = percentComplete + '%';
                                    
                                    if (percentComplete < 100) {
                                        progressStatus.textContent = `Uploading... ${formatBytes(e.loaded)} of ${formatBytes(e.total)}`;
                                    } else {
                                        progressStatus.textContent = 'Processing upload...';
                                    }
                                }
                            });
                            
                            xhr.addEventListener('load', function() {
                                if (xhr.status === 200 || xhr.status === 201) {
                                    progressStatus.textContent = 'Upload complete! Redirecting...';
                                    progressBar.style.width = '100%';
                                    progressPercent.textContent = '100%';
                                    
                                    // Redirect after a brief delay
                                    setTimeout(() => {
                                        window.location.href = form.action;
                                    }, 500);
                                } else {
                                    progressStatus.textContent = 'Upload failed. Please try again.';
                                    progressBar.classList.add('bg-red-600');
                                    submitBtn.disabled = false;
                                    submitBtn.textContent = 'Upload File';
                                }
                            });
                            
                            xhr.addEventListener('error', function() {
                                progressStatus.textContent = 'Network error. Please try again.';
                                progressBar.classList.add('bg-red-600');
                                submitBtn.disabled = false;
                                submitBtn.textContent = 'Upload File';
                            });
                            
                            xhr.open('POST', form.action);
                            xhr.send(formData);
                        });
                        
                        function formatBytes(bytes) {
                            if (bytes === 0) return '0 Bytes';
                            const k = 1024;
                            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
                            const i = Math.floor(Math.log(bytes) / Math.log(k));
                            return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
                        }
                    </script>

                    @if($link->expires)
                        <div class="alert-warning mt-4">
                            <p class="text-sm">
                                <span class="font-semibold">Note:</span> This link expires {{ $link->expires->diffForHumans() }}
                            </p>
                        </div>
                    @endif

                    <div class="alert-info mt-4">
                        <p class="text-sm">
                            <span class="font-semibold">One-time use:</span> This link will expire after uploading one file.
                        </p>
                    </div>
                @endif
        </div>
    </main>
</body>
</html>
