<?php

namespace App\Http\Controllers;

use App\Models\ShortURL;
use App\Models\User;
use Illuminate\Http\Request;

class ShortURLController extends Controller
{
    private function failure(Request $request, string $message, int $status)
    {
        if ($request->query("_back")) {
            return back()->with("error", $message);
        }

        return response()->json(['error' => $message], $status);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'url' => 'required|url'
        ]);

        $potentialBearer = $request->header('Authorization') ?? null;
        /** @var User|null */
        $uploader = $request->user() ?? (
            $potentialBearer ? User::firstWhere('api_token', $potentialBearer) : null
        ) ?? null;

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

        $_back = $request->query("_back");
        $customUrl = $request->input('custom_url', null);

        if ($customUrl) {
            if (!$uploader || !$uploader->isAdmin()) {
                return $this->failure($request, 'Custom URLs are restricted to administrators', 403);
            }

            if (!preg_match('/^[a-zA-Z0-9-_]{1,20}$/', $customUrl)) {
                return $this->failure($request, 'Invalid custom URL', 400);
            }

            if (ShortURL::where('short_code', $customUrl)->exists()) {
                return $this->failure($request, 'Custom URL already exists', 400);
            }
        }

        /** @var ShortURL */
        $shortURL = null;

        for ($attempt = 0; $shortURL === null; $attempt++) {
            try {
                $shortURL = ShortURL::create([
                    'url' => $request->url,
                    'short_code' => $customUrl ?? $this->generateShortcode(),
                    'expires' => $expireDate,
                    'user_id' => $uploader?->id,
                    'size' => strlen($request->url)
                ]);
            } catch (\Throwable $th) {
                // A generated shortcode can collide, so try again with a new one.
                // A custom one cannot be regenerated, and retrying would never end.
                if ($customUrl || $attempt >= 9) {
                    throw $th;
                }
            }
        }

        $url = url("/s/$shortURL->short_code");
        
        if ($_back) { return back()->with("short_url", $url); }
        
        return response()->json([
            'url' => $url,
            'short_code' => $shortURL->short_code
        ], 201);
    }

    public function redirect(string $shortCode)
    {
        /** @var ShortURL */
        $shortURL = ShortURL::where('short_code', $shortCode)->firstOrFail();
        $shortURL->increment('hits');
        return redirect($shortURL->url);
    }

    public function destroy(Request $request, string $shortCode)
    {
        $potentialBearer = $request->header('Authorization') ?? null;
        /** @var User|null */
        $uploader = $request->user() ?? (
            $potentialBearer ? User::firstWhere('api_token', $potentialBearer) : null
        ) ?? null;

        /** @var ShortURL */
        $shortURL = ShortURL::where('short_code', $shortCode)->firstOrFail();

        if ($uploader?->id !== $shortURL->user_id) {
            return response()->json([
                'error' => 'Unauthorized'
            ], 401);
        }

        $shortURL->delete();

        if ($request->query("_back")) { return back(); }
        
        return response()->json([
            'message' => 'Deleted'
        ]);
    }
}
