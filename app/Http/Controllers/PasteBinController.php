<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesApiUser;
use App\Models\PasteBin;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class PasteBinController extends Controller
{
    use ResolvesApiUser;

    /**
     * Store a newly created paste bin.
     */
    public function store(Request $request)
    {
        $request->validate([
            'content' => 'required|string',
            'password' => 'nullable|string',
            'expires' => 'nullable|integer',
        ]);

        /** @var User|null */
        $uploader = $this->resolveApiUser($request);

        // /p never honoured the allow_anonymous_upload setting, so anonymous
        // paste creation stayed open even when the instance disabled it.
        if ($authRes = $this->rejectIfNotAuthenticatedIfNeeded($uploader)) { return $authRes; }

        $password = $request->input('password') ?? $request->input('pwd') ?? null;

        $content = $request->input('content');

        if ($quotaRes = $this->rejectIfOverQuota($uploader, strlen($content))) {
            return $quotaRes;
        }

        do {
            $shortCode = $this->generateShortCode();
        } while (PasteBin::where('short_code', $shortCode)->exists());

        // Encrypt content if password is provided
        if ($password) {
            $encryptedContent = $this->encryptData($content, $password);
            $hashedPassword = Hash::make($password);
        } else {
            $encryptedContent = $content;
            $hashedPassword = null;
        }

        /** @var int|null */
        $expiresMins = $request->input('expires', null);

        if ($expiresMins !== null && $expiresMins < 0) {
            $expiresMins = 0;
        }

        if ($uploader) {
            $expireDate = $expiresMins ? now()->addMinutes((int)$expiresMins) : null;
        } else {
            // If no user is logged in, the paste will expire in 7 days maximum
            if ($expiresMins > 10080 || $expiresMins === null) {
                $expireDate = now()->addDays(7);
            } else {
                $expireDate = now()->addMinutes((int)$expiresMins);
            }
        }

        $pasteBin = PasteBin::create([
            'content' => $encryptedContent,
            'short_code' => $shortCode,
            'password' => $hashedPassword,
            'expires' => $expireDate,
            'user_id' => $uploader?->id,
            'size' => strlen($content),
        ]);

        if ($uploader) {
            $uploader->increment('storage_used', $pasteBin->size);
        }

        $url = url("/p/{$pasteBin->short_code}");

        if ($request->query("_back")) { return back()->with("short_url", $url); }
        
        return response()->json([
            'url' => $url,
            'short_code' => $pasteBin->short_code,
        ], 201);
    }

    /**
     * Display the specified paste bin.
     */
    public function show(Request $request, string $shortCode)
    {
        $pasteBin = PasteBin::where('short_code', $shortCode)
            ->where(function ($query) {
                $query->whereNull('expires')
                      ->orWhere('expires', '>', now());
            })
            ->firstOrFail();

        $password = $request->input('password') ?? $request->input('pwd') ?? null;

        if ($pasteBin->password) {
            if (!$password || !Hash::check($password, $pasteBin->password)) {
                return response()->json(['message' => 'Invalid password.'], 403);
            }

            // Decrypt content
            $content = $this->decryptData($pasteBin->content, $password);

            if ($content === false) {
                return response()->json(['message' => 'Failed to decrypt content.'], 500);
            }
        } else {
            $content = $pasteBin->content;
        }

        return response($content, 200, ['Content-Type' => 'text/plain']);
    }

    /**
     * Delete the specified paste bin.
     */
    public function destroy(Request $request, string $shortCode)
    {
        $pasteBin = PasteBin::where('short_code', $shortCode)->firstOrFail();

        /** @var User|null */
        $user = $this->resolveApiUser($request);

        $password = $request->input('password') ?? $request->input('pwd') ?? null;

        if ($user && $pasteBin->user_id && $user->id === $pasteBin->user_id) {
            // User is authenticated and owns the paste bin
        } elseif ($pasteBin->password) {
            // Paste bin is password protected. This compared the submitted
            // plaintext against the stored bcrypt hash, so it never matched and
            // protected pastes could not be deleted at all.
            if (!$password || !Hash::check($password, $pasteBin->password)) {
                return response()->json(['error' => 'Invalid password'], 403);
            }
        } else {
            // No permission to delete
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $pasteBin->delete();

        if ($request->query("_back")) { return back(); }

        return response()->json(['message' => 'Paste bin deleted'], 204);
    }
}
