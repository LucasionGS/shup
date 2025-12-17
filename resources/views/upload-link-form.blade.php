<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload File</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-gray-100">
    <div class="flex justify-center items-center min-h-screen">
        <div class="w-full max-w-md">
            <div class="bg-white shadow-lg rounded-lg p-8">
                <h1 class="text-2xl font-bold mb-6 text-center text-gray-800">Upload Your File</h1>
                
                @if(session('file_url'))
                    <div class="mb-4 p-4 bg-green-100 border border-green-400 text-green-700 rounded-lg">
                        <p class="font-semibold mb-2">File uploaded successfully!</p>
                        <div class="flex items-center gap-2">
                            <input 
                                type="text" 
                                value="{{ session('file_url') }}" 
                                readonly 
                                class="flex-1 p-2 border border-gray-300 rounded bg-gray-50 text-sm"
                            >
                            <button 
                                data-clipboard-text="{{ session('file_url') }}"
                                class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded text-sm"
                            >
                                Copy
                            </button>
                        </div>
                        <p class="text-sm mt-2">This upload link can no longer be used.</p>
                    </div>
                @else
                    <!-- Progress Bar (hidden by default) -->
                    <div id="progress-container" class="hidden mb-4">
                        <div class="flex justify-between items-center mb-2">
                            <span class="text-sm font-medium text-gray-700">Uploading...</span>
                            <span id="progress-percent" class="text-sm font-medium text-blue-600">0%</span>
                        </div>
                        <div class="w-full bg-gray-200 rounded-full h-3 overflow-hidden">
                            <div 
                                id="progress-bar" 
                                class="bg-blue-600 h-3 rounded-full transition-all duration-300"
                                style="width: 0%"
                            ></div>
                        </div>
                        <p class="text-xs text-gray-500 mt-1" id="progress-status">Preparing upload...</p>
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
                            <label for="file" class="block text-sm font-medium text-gray-700 mb-2">
                                Select File
                            </label>
                            <input 
                                type="file" 
                                name="file" 
                                id="file" 
                                required
                                class="w-full border border-gray-300 rounded-lg p-2 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100"
                            >
                            @error('file')
                                <div class="text-red-500 text-sm mt-1">{{ $message }}</div>
                            @enderror
                        </div>

                        <div>
                            <label for="password" class="block text-sm font-medium text-gray-700 mb-2">
                                Password (optional)
                            </label>
                            <input 
                                type="password" 
                                name="password" 
                                id="password"
                                placeholder="Leave empty for no password"
                                class="w-full border border-gray-300 rounded-lg p-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                            >
                            <p class="text-xs text-gray-500 mt-1">
                                If set, the file will be encrypted and require this password to access.
                            </p>
                            @error('password')
                                <div class="text-red-500 text-sm mt-1">{{ $message }}</div>
                            @enderror
                        </div>

                        @if($errors->has('error'))
                            <div class="p-3 bg-red-100 border border-red-400 text-red-700 rounded-lg text-sm">
                                {{ $errors->first('error') }}
                            </div>
                        @endif

                        <button 
                            type="submit"
                            id="submit-btn"
                            class="w-full bg-blue-500 hover:bg-blue-600 text-white font-semibold py-3 px-4 rounded-lg transition duration-200 disabled:opacity-50 disabled:cursor-not-allowed"
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
                        <div class="mt-4 p-3 bg-yellow-50 border border-yellow-200 rounded-lg">
                            <p class="text-sm text-yellow-800">
                                <span class="font-semibold">Note:</span> This link expires {{ $link->expires->diffForHumans() }}
                            </p>
                        </div>
                    @endif

                    <div class="mt-4 p-3 bg-blue-50 border border-blue-200 rounded-lg">
                        <p class="text-sm text-blue-800">
                            <span class="font-semibold">⚠️ One-time use:</span> This link will expire after uploading one file.
                        </p>
                    </div>
                @endif
            </div>
        </div>
    </div>
</body>
</html>
