<?php

namespace App\Http\Controllers;

use App\Models\File;
use App\Models\User;
use Auth;
use Crypt;
use Hash;
use Illuminate\Http\Request;

class FileController extends Controller
{
    /**
     * Display the specified resource.
     */
    public function show(Request $request, string $shortCode)
    {
        /** @var File */
        $file = File::where('short_code', $shortCode)->firstOrFail();

        $password = $request->input('password') ?? $request->input("pwd") ?? null;

        if ($file->password) {
            if (!$password) {
                return view('file-password');
            }

            if (!Hash::check($password, $file->password)) {
                return view('file-password');
            }
        }
        
        if (!Auth::check() || Auth::id() !== $file->user_id) {
            $file->increment('downloads');
        }
        
        // $file->increment('downloads');
        $path = "app/private/files/$shortCode";
        
        $path = storage_path($path);

        if ($file->password) {
            // DECRYPTION
            $fileContent = file_get_contents($path);
            $decrypted = $this->decryptData($fileContent, $password);
            
            $mime = $file->mime ?? 'application/octet-stream';
            
            $headers = [
                'Content-Type' => $mime,
            ];

            if (
                str_starts_with($mime, "image/")
                || str_starts_with($mime, "video/")
            ) {
                $headers['Content-Disposition'] = "attachment; filename=\"{$file->original_name}\"";
            }
            
            return response($decrypted, 200, $headers);
            // /DECRYPTION
        }

        return response()->file(
            $path
        );
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'file' => 'required|file',
            'password' => 'string'
        ]);

        $potentialBearer = $request->header("Authorization") ?? null; // Bearer token
        /** @var User|null */
        $uploader = $request->user() ?? (
            $potentialBearer ? User::firstWhere('api_token', $potentialBearer) : null
        ) ?? null;

        if ($authRes = $this->rejectIfNotAuthenticatedIfNeeded($uploader)) { return $authRes; }
        
        $file = $request->file('file');
        /** @var string|null */
        $password = $request->input('password') ?? $request->input("pwd") ?? null;

        /** @var int|null */
        $expiresMins = $request->input('expires', null);

        $fileName = $file->getClientOriginalName();
        $ext = $file->guessExtension();
        $shortCode = $this->generateShortcode();
        // $newFilename = $shortCode;
        // if ($ext) {
        //     $newFilename .= ".$ext";
        // }
        $file->storeAs("files", $password ? "__$shortCode" : $shortCode);
        
        if ($expiresMins && $expiresMins < 0) {
            $expiresMins = 0;
        }
        
        if ($uploader) {
            $expireDate = $expiresMins ? now()->addMinutes((int)$expiresMins) : null;
        }
        else {
            // If no user is logged in, the file will be deleted in 7 days. Cannot be set over 7 days.
            if ($expiresMins > 10080) {
                $expireDate = now()->addDays(7);
            }
            else {
                $expireDate = $expiresMins ? now()->addMinutes((int)$expiresMins) : now()->addDays(7);
            }
        }

        // Encrypt file and store without __
        $path = "app/private/files";
        $path = storage_path($path);

        // ENCRYPTION
        if ($password) {
            $file = file_get_contents("$path/__$shortCode");
            $encrypted = $this->encryptData($file, $password);
            file_put_contents("$path/$shortCode", $encrypted);
            unlink("$path/__$shortCode");
        }
        // /ENCRYPTION

        $url = url("/f/$shortCode");

        $filesize = filesize("$path/$shortCode");
        File::create([
            'short_code' => $shortCode,
            'original_name' => $fileName,
            'ext' => $ext,
            'mime' => $file->getMimeType(),
            'password' => $password ? Hash::make($password) : null,
            'user_id' => $uploader?->id,
            'expires' => $expireDate,
            'size' => $filesize
        ]);

        if ($uploader) {
            $uploader->increment('storage_used', $filesize);
        }

        if ($request->query("_back")) { return back(); }
        
        return response()->json([
            'url' => $url,
            'short_code' => $shortCode
        ], 201);
    }

    public function destroy(Request $request, string $shortCode)
    {
        if (!File::where('short_code', $shortCode)->exists()) {
            return response()->json([
                'error' => 'File not found'
            ], 404);
        }
        
        /** @var File */
        $file = File::where('short_code', $shortCode)->firstOrFail();

        $forced = $request->input("force") === "1";
        
        $password = $request->input('password') ?? $request->input("pwd") ?? null;
        
        if ($forced && $request->user() && $request->user()->id === $file->user_id) {
            // Do nothing but skip the password check
        }
        else if ($file->password && (!$password || !Hash::check($password, $file->password))) {
            return response()->json([
                'error' => 'Invalid password'
            ], 403);
        }
        
        $file->expire();

        if ($request->query("_back")) { return back(); }
        
        return response()->json([
            'message' => 'File deleted'
        ], 204);
    }
}
