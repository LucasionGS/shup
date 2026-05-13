<?php

namespace App\Http\Controllers;

use App\Models\Directory;
use App\Models\DirectoryItem;
use App\Models\User;
use App\Support\StreamingZip;
use Hash;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DirectoryController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:120',
            'description' => 'nullable|string|max:1000',
            'password' => 'nullable|string|max:255',
            'expires' => 'nullable|integer|min:0',
            'files' => 'nullable|array',
            'files.*' => 'file',
            'paths' => 'nullable|array',
            'paths.*' => 'string|max:2048',
        ]);

        $user = $this->resolveUser($request);

        if (!$user) {
            return $this->unauthorizedResponse($request);
        }

        $expiresMins = $request->input('expires');
        $expireDate = $expiresMins ? now()->addMinutes((int) $expiresMins) : null;

        do {
            $shortCode = $this->generateShortcode();
        } while (Directory::where('short_code', $shortCode)->exists());

        /** @var Directory $directory */
        $directory = Directory::create([
            'short_code' => $shortCode,
            'name' => $request->input('name'),
            'description' => $request->input('description'),
            'password' => $request->filled('password') ? Hash::make($request->input('password')) : null,
            'expires' => $expireDate,
            'user_id' => $user->id,
            'size' => 0,
        ]);

        try {
            if ($request->hasFile('files')) {
                $this->importUploadedFiles($request, $directory, '');
            }
        } catch (ValidationException $exception) {
            $directory->expire();
            throw $exception;
        }

        $url = url("/d/$directory->short_code");

        if ($request->query('_back')) {
            return back()->with('directory_url', $url);
        }

        return response()->json([
            'url' => $url,
            'short_code' => $directory->short_code,
        ], 201);
    }

    public function show(Request $request, string $shortCode, ?string $path = null)
    {
        /** @var Directory $directory */
        $directory = $this->findActiveDirectory($shortCode);
        $isOwner = $this->ownsDirectory($request, $directory);
        $password = $request->input('password') ?? $request->input('pwd') ?? null;

        if (!$isOwner && $directory->password && (!$password || !Hash::check($password, $directory->password))) {
            return view('directory-password', ['directory' => $directory]);
        }

        try {
            $currentPath = $this->normalizePath($path ?? '');
        } catch (ValidationException) {
            abort(404);
        }

        $currentFolder = null;

        if ($currentPath !== '') {
            $currentItem = $this->findItem($directory, $currentPath);

            if (!$currentItem) {
                abort(404);
            }

            if ($currentItem->isFile()) {
                return $this->downloadFile($request, $currentItem);
            }

            $currentFolder = $currentItem;
        }

        $children = $this->childrenFor($directory, $currentPath);

        return view('directory-show', [
            'directory' => $directory,
            'currentPath' => $currentPath,
            'currentFolder' => $currentFolder,
            'children' => $children,
            'breadcrumbs' => $this->breadcrumbsFor($directory, $currentPath),
            'isOwner' => $isOwner,
            'password' => $isOwner ? null : $password,
        ]);
    }

    public function upload(Request $request, string $shortCode)
    {
        $request->validate([
            'current_path' => 'nullable|string|max:2048',
            'files' => 'required|array|min:1',
            'files.*' => 'file',
            'paths' => 'nullable|array',
            'paths.*' => 'string|max:2048',
        ]);

        $directory = $this->findActiveDirectory($shortCode);
        $owner = $this->requireOwner($request, $directory);

        if (!$owner) {
            return $this->unauthorizedResponse($request);
        }

        $currentPath = $this->normalizePath($request->input('current_path', ''));
        $this->requireFolder($directory, $currentPath);
        $uploadedCount = $this->importUploadedFiles($request, $directory, $currentPath);

        if ($request->query('_back')) {
            return back()->with('directory_info', "$uploadedCount item(s) uploaded.");
        }

        return response()->json([
            'message' => 'Files uploaded',
            'uploaded' => $uploadedCount,
            'url' => url('/d/' . $directory->short_code . ($currentPath ? '/' . DirectoryItem::encodePath($currentPath) : '')),
        ]);
    }

    public function storeFolder(Request $request, string $shortCode)
    {
        $request->validate([
            'current_path' => 'nullable|string|max:2048',
            'name' => 'required|string|max:255',
        ]);

        $directory = $this->findActiveDirectory($shortCode);
        $owner = $this->requireOwner($request, $directory);

        if (!$owner) {
            return $this->unauthorizedResponse($request);
        }

        $currentPath = $this->normalizePath($request->input('current_path', ''));
        $this->requireFolder($directory, $currentPath);

        $folderName = $this->normalizePath($request->input('name'), false, $currentPath === '');
        $folderPath = $this->joinPaths($currentPath, $folderName);
        $this->ensureFolderPath($directory, $folderPath);

        if ($request->query('_back')) {
            return back()->with('directory_info', 'Folder created.');
        }

        return response()->json([
            'message' => 'Folder created',
            'path' => $folderPath,
        ], 201);
    }

    public function destroyEntry(Request $request, string $shortCode)
    {
        $request->validate([
            'path' => 'required|string|max:2048',
        ]);

        $directory = $this->findActiveDirectory($shortCode);
        $owner = $this->requireOwner($request, $directory);

        if (!$owner) {
            return $this->unauthorizedResponse($request);
        }

        $path = $this->normalizePath($request->input('path'), false);
        $item = $this->findItem($directory, $path);

        if (!$item) {
            abort(404);
        }

        $this->deleteItem($directory, $item);

        if ($request->query('_back')) {
            return back()->with('directory_info', 'Entry deleted.');
        }

        return response()->json(['message' => 'Entry deleted'], 204);
    }

    public function destroy(Request $request, string $shortCode)
    {
        $directory = Directory::where('short_code', $shortCode)->firstOrFail();
        $owner = $this->requireOwner($request, $directory);

        if (!$owner) {
            return $this->unauthorizedResponse($request);
        }

        $directory->expire();

        if ($request->query('_back')) {
            return back()->with('directory_info', 'Directory deleted.');
        }

        return response()->json(['message' => 'Directory deleted'], 204);
    }

    public function zip(Request $request, string $shortCode, ?string $path = null)
    {
        $directory = $this->findActiveDirectory($shortCode);
        $isOwner = $this->ownsDirectory($request, $directory);
        $password = $request->input('password') ?? $request->input('pwd') ?? null;

        if (!$isOwner && $directory->password && (!$password || !Hash::check($password, $directory->password))) {
            return view('directory-password', ['directory' => $directory]);
        }

        try {
            $currentPath = $this->normalizePath($path ?? '');
        } catch (ValidationException) {
            abort(404);
        }

        $this->requireFolder($directory, $currentPath);

        $items = $directory->items()->get();
        $folders = $items
            ->filter(fn (DirectoryItem $item) => $item->isFolder() && $this->isWithinPath($item->path, $currentPath))
            ->sortBy('path')
            ->values();
        $files = $items
            ->filter(fn (DirectoryItem $item) => $item->isFile() && $this->isWithinPath($item->path, $currentPath))
            ->sortBy('path')
            ->values();
        $downloadName = $this->zipDownloadName($directory, $currentPath);

        return response()->streamDownload(function () use ($folders, $files, $currentPath) {
            $zip = new StreamingZip();

            foreach ($folders as $folder) {
                $zipPath = $this->relativeZipPath($folder->path, $currentPath);

                if ($zipPath !== '') {
                    $zip->addDirectory($zipPath);
                }
            }

            foreach ($files as $file) {
                if (!$file->storage_path) {
                    continue;
                }

                $zip->addFileFromPath(
                    $this->relativeZipPath($file->path, $currentPath),
                    Storage::disk('local')->path($file->storage_path)
                );
            }

            $zip->finish();
        }, $downloadName, [
            'Content-Type' => 'application/zip',
        ]);
    }

    private function importUploadedFiles(Request $request, Directory $directory, string $currentPath): int
    {
        $files = $request->file('files', []);
        $paths = array_values($request->input('paths', []));

        if ($files instanceof UploadedFile) {
            $files = [$files];
        }

        $files = array_values($files);
        $uploadedCount = 0;

        foreach ($files as $index => $uploadedFile) {
            if (!$uploadedFile instanceof UploadedFile) {
                continue;
            }

            $rawPath = $paths[$index] ?? $uploadedFile->getClientOriginalName();
            $relativePath = $this->normalizePath($rawPath, false, $currentPath === '');
            $targetPath = $this->joinPaths($currentPath, $relativePath);
            $parentPath = DirectoryItem::parentPathFor($targetPath);

            if ($parentPath !== '') {
                $this->ensureFolderPath($directory, $parentPath);
            }

            $this->storeFileItem($directory, $targetPath, $uploadedFile);
            $uploadedCount++;
        }

        if ($uploadedCount === 0) {
            throw ValidationException::withMessages([
                'files' => 'Upload at least one file.',
            ]);
        }

        return $uploadedCount;
    }

    private function storeFileItem(Directory $directory, string $path, UploadedFile $uploadedFile): void
    {
        $existing = $this->findItem($directory, $path);

        if ($existing && $existing->isFolder()) {
            throw ValidationException::withMessages([
                'paths' => "A folder already exists at $path.",
            ]);
        }

        $storedName = (string) Str::uuid();
        $storagePath = $uploadedFile->storeAs("directories/$directory->short_code/files", $storedName);
        $size = $uploadedFile->getSize() ?? 0;
        $delta = $size;

        if ($existing) {
            if ($existing->storage_path) {
                Storage::disk('local')->delete($existing->storage_path);
            }

            $delta -= $existing->size;
            $existing->update([
                'name' => DirectoryItem::nameFromPath($path),
                'mime' => $uploadedFile->getMimeType() ?: 'application/octet-stream',
                'size' => $size,
                'storage_path' => $storagePath,
            ]);
        } else {
            DirectoryItem::create([
                'directory_id' => $directory->id,
                'type' => DirectoryItem::TYPE_FILE,
                'path' => $path,
                'name' => DirectoryItem::nameFromPath($path),
                'mime' => $uploadedFile->getMimeType() ?: 'application/octet-stream',
                'size' => $size,
                'storage_path' => $storagePath,
            ]);
        }

        $this->applySizeDelta($directory, $delta);
    }

    private function ensureFolderPath(Directory $directory, string $path): void
    {
        if ($path === '') {
            return;
        }

        $segments = explode('/', $path);
        $folderPath = '';

        foreach ($segments as $segment) {
            $folderPath = $this->joinPaths($folderPath, $segment);
            $existing = $this->findItem($directory, $folderPath);

            if ($existing && $existing->isFile()) {
                throw ValidationException::withMessages([
                    'path' => "A file already exists at $folderPath.",
                ]);
            }

            if (!$existing) {
                DirectoryItem::create([
                    'directory_id' => $directory->id,
                    'type' => DirectoryItem::TYPE_FOLDER,
                    'path' => $folderPath,
                    'name' => $segment,
                    'size' => 0,
                ]);
            }
        }
    }

    private function requireFolder(Directory $directory, string $path): void
    {
        if ($path === '') {
            return;
        }

        $item = $this->findItem($directory, $path);

        if (!$item || !$item->isFolder()) {
            abort(404);
        }
    }

    private function deleteItem(Directory $directory, DirectoryItem $item): void
    {
        $items = $directory->items()->get()->filter(function (DirectoryItem $candidate) use ($item) {
            return $candidate->path === $item->path || str_starts_with($candidate->path, "$item->path/");
        });
        $removedSize = (int) $items->sum('size');

        foreach ($items as $candidate) {
            if ($candidate->isFile() && $candidate->storage_path) {
                Storage::disk('local')->delete($candidate->storage_path);
            }
        }

        DirectoryItem::whereIn('id', $items->pluck('id'))->delete();
        $this->applySizeDelta($directory, -$removedSize);
    }

    private function downloadFile(Request $request, DirectoryItem $item)
    {
        if (!$item->storage_path) {
            abort(404);
        }

        $path = Storage::disk('local')->path($item->storage_path);

        if (!is_file($path)) {
            abort(404);
        }

        if ($request->boolean('preview') && $this->isPreviewableMedia($item)) {
            return response()->file($path, [
                'Content-Type' => $item->mime ?: 'application/octet-stream',
            ]);
        }

        return response()->download($path, $item->name, [
            'Content-Type' => $item->mime ?: 'application/octet-stream',
        ]);
    }

    private function isPreviewableMedia(DirectoryItem $item): bool
    {
        $mime = $item->mime ?? '';

        return str_starts_with($mime, 'image/')
            || str_starts_with($mime, 'audio/')
            || str_starts_with($mime, 'video/');
    }

    private function findActiveDirectory(string $shortCode): Directory
    {
        return Directory::where('short_code', $shortCode)
            ->where(function ($query) {
                $query->whereNull('expires')->orWhere('expires', '>', now());
            })
            ->firstOrFail();
    }

    private function findItem(Directory $directory, string $path): ?DirectoryItem
    {
        /** @var DirectoryItem|null $item */
        $item = $directory->items()
            ->where('path_hash', DirectoryItem::pathHash($path))
            ->first();

        if (!$item || $item->path !== $path) {
            return null;
        }

        return $item;
    }

    private function childrenFor(Directory $directory, string $path): Collection
    {
        return $directory->items()->get()
            ->filter(fn (DirectoryItem $item) => $item->parentPath() === $path)
            ->sort(function (DirectoryItem $first, DirectoryItem $second) {
                if ($first->type !== $second->type) {
                    return $first->isFolder() ? -1 : 1;
                }

                return strnatcasecmp($first->name, $second->name);
            })
            ->values();
    }

    private function breadcrumbsFor(Directory $directory, string $path): array
    {
        $breadcrumbs = [[
            'name' => $directory->name,
            'path' => '',
        ]];

        if ($path === '') {
            return $breadcrumbs;
        }

        $segments = explode('/', $path);
        $currentPath = '';

        foreach ($segments as $segment) {
            $currentPath = $this->joinPaths($currentPath, $segment);
            $breadcrumbs[] = [
                'name' => $segment,
                'path' => $currentPath,
            ];
        }

        return $breadcrumbs;
    }

    private function resolveUser(Request $request): ?User
    {
        $token = $request->bearerToken() ?: $request->header('Authorization');

        if ($token && str_starts_with($token, 'Bearer ')) {
            $token = substr($token, 7);
        }

        return $request->user() ?? ($token ? User::firstWhere('api_token', $token) : null);
    }

    private function ownsDirectory(Request $request, Directory $directory): bool
    {
        $user = $this->resolveUser($request);

        return $user && $directory->user_id === $user->id;
    }

    private function requireOwner(Request $request, Directory $directory): ?User
    {
        $user = $this->resolveUser($request);

        if (!$user) {
            return null;
        }

        if ($directory->user_id !== $user->id) {
            abort(403);
        }

        return $user;
    }

    private function unauthorizedResponse(Request $request)
    {
        if ($request->query('_back')) {
            return back()->withErrors(['auth' => 'You need to be logged in.']);
        }

        return response()->json(['error' => 'Unauthorized'], 401);
    }

    private function applySizeDelta(Directory $directory, int $delta): void
    {
        if ($delta === 0) {
            return;
        }

        $directory->refresh();
        $directory->size = max(0, $directory->size + $delta);
        $directory->save();

        if (!$directory->user_id) {
            return;
        }

        /** @var User|null $user */
        $user = User::find($directory->user_id);

        if ($user) {
            $user->storage_used = max(0, $user->storage_used + $delta);
            $user->save();
        }
    }

    private function normalizePath(?string $path, bool $allowRoot = true, bool $rejectReservedRoot = true): string
    {
        $path = (string) $path;

        if (str_contains($path, "\0")) {
            throw ValidationException::withMessages(['path' => 'Paths cannot contain null bytes.']);
        }

        $trimmedPath = trim($path);

        if ($trimmedPath === '') {
            if ($allowRoot) {
                return '';
            }

            throw ValidationException::withMessages(['path' => 'Path cannot be empty.']);
        }

        if (str_starts_with($trimmedPath, '/') || str_starts_with($trimmedPath, '\\') || preg_match('/^[A-Za-z]:/', $trimmedPath)) {
            throw ValidationException::withMessages(['path' => 'Use relative paths only.']);
        }

        $normalized = str_replace('\\', '/', $trimmedPath);
        $normalized = preg_replace('#/+#', '/', $normalized) ?? $normalized;
        $normalized = trim($normalized, '/');

        if ($normalized === '') {
            if ($allowRoot) {
                return '';
            }

            throw ValidationException::withMessages(['path' => 'Path cannot be empty.']);
        }

        if (strlen($normalized) > 2048) {
            throw ValidationException::withMessages(['path' => 'Path is too long.']);
        }

        $segments = explode('/', $normalized);

        foreach ($segments as $index => $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw ValidationException::withMessages(['path' => 'Path contains an invalid segment.']);
            }

            if ($index === 0 && $rejectReservedRoot && $segment === '-') {
                throw ValidationException::withMessages(['path' => 'That folder name is reserved.']);
            }

            if (strlen($segment) > 255) {
                throw ValidationException::withMessages(['path' => 'Folder or file names must be 255 characters or less.']);
            }
        }

        return $normalized;
    }

    private function joinPaths(string ...$paths): string
    {
        $paths = array_filter($paths, fn (string $path) => $path !== '');

        return implode('/', $paths);
    }

    private function isWithinPath(string $path, string $basePath): bool
    {
        return $basePath === '' || $path === $basePath || str_starts_with($path, "$basePath/");
    }

    private function relativeZipPath(string $path, string $basePath): string
    {
        if ($basePath === '') {
            return $path;
        }

        if ($path === $basePath) {
            return '';
        }

        return substr($path, strlen($basePath) + 1);
    }

    private function zipDownloadName(Directory $directory, string $currentPath): string
    {
        $name = $currentPath === '' ? $directory->name : DirectoryItem::nameFromPath($currentPath);
        $name = trim(str_replace(['/', '\\'], '-', $name));

        if ($name === '') {
            $name = $directory->short_code;
        }

        return "$name.zip";
    }
}