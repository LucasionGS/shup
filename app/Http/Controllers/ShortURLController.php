<?php

namespace App\Http\Controllers;

use App\Models\ShortURL;
use App\Models\User;
use Illuminate\Http\Request;

class ShortURLController extends Controller
{
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
        
        urlCreation:
        try {
            /** @var ShortURL */
            $shortURL = ShortURL::create([
                'url' => $request->url,
                'short_code' => $this->generateShortcode(),
                'expires' => $expireDate,
                'user_id' => $uploader?->id,
                'size' => strlen($request->url)
            ]);
        } catch (\Throwable $th) {
            goto urlCreation; // If the generated shortcode already exists, try again
        }

        $url = url("/s/$shortURL->short_code");
        
        if ($request->query("_back")) { return back(); }
        
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
