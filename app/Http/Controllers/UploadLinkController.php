<?php

namespace App\Http\Controllers;

use App\Models\Directory;
use App\Models\DirectoryItem;
use App\Models\File;
use App\Models\UploadLink;
use App\Models\User;
use Hash;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class UploadLinkController extends Controller
{
    /**
     * Generate a new upload link
     */
    public function store(Request $request)
    {
        $request->validate([
            'expires' => 'nullable|integer|min:0',
            'multi_file' => 'nullable|boolean',
        ]);

        if ($authRes = $this->rejectIfNotAuthenticated()) {
            return $authRes;
        }

        /** @var User */
        $user = $request->user();

        $shortCode = $this->generateShortcode();
        $expiresMins = $request->input('expires', null);
        $expireDate = $expiresMins ? now()->addMinutes((int)$expiresMins) : null;

        $link = UploadLink::create([
            'short_code' => $shortCode,
            'user_id' => $user->id,
            'expires' => $expireDate,
            'multi_file' => $request->boolean('multi_file'),
        ]);

        $url = url("/ul/$shortCode");

        if ($request->query("_back")) {
            return back()->with("upload_link", $url);
        }

        return response()->json([
            'url' => $url,
            'short_code' => $shortCode,
            'expires' => $expireDate,
            'multi_file' => $link->multi_file,
        ], 201);
    }

    /**
     * Display upload form for the link
     */
    public function show(string $shortCode)
    {
        /** @var UploadLink|null */
        $link = UploadLink::where('short_code', $shortCode)->first();

        // If there's a success message in the session, show the form with success
        // even if the link is now invalid (it was just used)
        if (session('file_url') && $link) {
            return view('upload-link-form', ['link' => $link]);
        }

        if (!$link || !$link->isValid()) {
            return view('upload-link-invalid');
        }

        return view('upload-link-form', ['link' => $link]);
    }

    /**
     * Handle file upload through the link
     */
    public function upload(Request $request, string $shortCode)
    {
        /** @var UploadLink|null */
        $link = UploadLink::where('short_code', $shortCode)->first();

        if (!$link || !$link->isValid()) {
            if ($request->query("_back")) {
                return back()->withErrors(['error' => 'Upload link is invalid or has been used.']);
            }
            return response()->json(['error' => 'Upload link is invalid or has been used.'], 400);
        }

        if ($link->multi_file) {
            $request->validate([
                'files' => 'required|array|min:1',
                'files.*' => 'file',
                'password' => 'nullable|string',
            ]);
        } else {
            $request->validate([
                'file' => 'required|file',
                'password' => 'nullable|string',
            ]);
        }

        /** @var User */
        $owner = $link->user;

        $password = $request->input('password') ?? $request->input("pwd") ?? null;

        if ($link->multi_file) {
            $files = $request->file('files', []);

            if ($files instanceof UploadedFile) {
                $files = [$files];
            }

            $files = array_values(array_filter($files, fn ($f) => $f instanceof UploadedFile));

            // Multiple files become a directory; a single file stays a regular file
            if (count($files) > 1) {
                $url = $this->storeAsDirectory($files, $owner, $password);

                $link->markUsed();

                if ($request->query("_back")) {
                    return back()->with("file_url", $url);
                }

                return response()->json([
                    'url' => $url,
                    'message' => 'Files uploaded successfully'
                ], 201);
            }

            $file = $files[0];
        } else {
            $file = $request->file('file');
        }

        $fileName = $file->getClientOriginalName();
        $ext = $file->guessExtension();
        $fileShortCode = $this->generateShortcode();

        $file->storeAs("files", $password ? "__$fileShortCode" : $fileShortCode);

        $path = "app/private/files";
        $path = storage_path($path);

        // ENCRYPTION
        if ($password) {
            $fileContent = file_get_contents("$path/__$fileShortCode");
            $encrypted = $this->encryptData($fileContent, $password);
            file_put_contents("$path/$fileShortCode", $encrypted);
            unlink("$path/__$fileShortCode");
        }
        // /ENCRYPTION

        $filesize = filesize("$path/$fileShortCode");

        File::create([
            'short_code' => $fileShortCode,
            'original_name' => $fileName,
            'ext' => $ext,
            'mime' => $file->getMimeType(),
            'password' => $password ? Hash::make($password) : null,
            'user_id' => $owner->id,
            'expires' => null,
            'size' => $filesize
        ]);

        $owner->increment('storage_used', $filesize);

        // Mark link as used
        $link->markUsed();

        $url = url("/f/$fileShortCode");

        if ($request->query("_back")) {
            return back()->with("file_url", $url);
        }

        return response()->json([
            'url' => $url,
            'short_code' => $fileShortCode,
            'message' => 'File uploaded successfully'
        ], 201);
    }

    /**
     * Delete/revoke an upload link
     */
    public function destroy(Request $request, string $shortCode)
    {
        /** @var UploadLink|null */
        $link = UploadLink::where('short_code', $shortCode)->first();

        if (!$link) {
            return response()->json(['error' => 'Upload link not found'], 404);
        }

        // Only the owner can delete the link
        if ($authRes = $this->rejectIfNotAuthenticated()) {
            return $authRes;
        }

        /** @var User */
        $user = $request->user();

        if ($link->user_id !== $user->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $link->expire();

        if ($request->query("_back")) {
            return back()->with('success', 'Upload link deleted');
        }

        return response()->json(['message' => 'Upload link deleted'], 204);
    }

    /**
     * Store multiple uploaded files as a directory owned by the link's creator
     *
     * @param UploadedFile[] $files
     */
    private function storeAsDirectory(array $files, User $owner, ?string $password): string
    {
        do {
            $shortCode = $this->generateShortcode();
        } while (Directory::where('short_code', $shortCode)->exists());

        /** @var Directory $directory */
        $directory = Directory::create([
            'short_code' => $shortCode,
            'name' => 'Upload ' . now()->format('Y-m-d H:i'),
            'description' => 'Uploaded through a one-time upload link.',
            'password' => $password ? Hash::make($password) : null,
            'expires' => null,
            'user_id' => $owner->id,
            'size' => 0,
        ]);

        $usedNames = [];
        $totalSize = 0;

        foreach ($files as $file) {
            $name = $this->uniqueFileName($file->getClientOriginalName(), $usedNames);
            $storedName = (string) Str::uuid();
            $storagePath = $file->storeAs("directories/$directory->short_code/files", $storedName);
            $size = $file->getSize() ?? 0;

            DirectoryItem::create([
                'directory_id' => $directory->id,
                'type' => DirectoryItem::TYPE_FILE,
                'path' => $name,
                'name' => $name,
                'mime' => $file->getMimeType() ?: 'application/octet-stream',
                'size' => $size,
                'storage_path' => $storagePath,
            ]);

            $totalSize += $size;
        }

        $directory->size = $totalSize;
        $directory->save();

        $owner->increment('storage_used', $totalSize);

        return url("/d/$directory->short_code");
    }

    /**
     * Sanitize a client filename into a unique root-level directory item name
     *
     * @param array<string, true> $usedNames
     */
    private function uniqueFileName(?string $original, array &$usedNames): string
    {
        $name = trim(basename(str_replace(['\\', "\0"], ['/', ''], (string) $original)));

        // '-' is reserved at the directory root for action routes (/d/{code}/-/...)
        if ($name === '' || $name === '.' || $name === '..' || $name === '-') {
            $name = 'file';
        }

        if (strlen($name) > 200) {
            $name = substr($name, -200);
        }

        $candidate = $name;
        $counter = 1;

        while (isset($usedNames[$candidate])) {
            $dot = strrpos($name, '.');
            $candidate = ($dot === false || $dot === 0)
                ? "$name ($counter)"
                : substr($name, 0, $dot) . " ($counter)" . substr($name, $dot);
            $counter++;
        }

        $usedNames[$candidate] = true;

        return $candidate;
    }
}
