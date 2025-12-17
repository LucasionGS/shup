<?php

namespace App\Http\Controllers;

use App\Models\File;
use App\Models\UploadLink;
use App\Models\User;
use Hash;
use Illuminate\Http\Request;

class UploadLinkController extends Controller
{
    /**
     * Generate a new upload link
     */
    public function store(Request $request)
    {
        $request->validate([
            'expires' => 'nullable|integer|min:0',
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
        ]);

        $url = url("/ul/$shortCode");

        if ($request->query("_back")) {
            return back()->with("upload_link", $url);
        }

        return response()->json([
            'url' => $url,
            'short_code' => $shortCode,
            'expires' => $expireDate,
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
        $request->validate([
            'file' => 'required|file',
            'password' => 'nullable|string',
        ]);

        /** @var UploadLink|null */
        $link = UploadLink::where('short_code', $shortCode)->first();

        if (!$link || !$link->isValid()) {
            if ($request->query("_back")) {
                return back()->withErrors(['error' => 'Upload link is invalid or has been used.']);
            }
            return response()->json(['error' => 'Upload link is invalid or has been used.'], 400);
        }

        /** @var User */
        $owner = $link->user;

        $file = $request->file('file');
        $password = $request->input('password') ?? $request->input("pwd") ?? null;

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
}
