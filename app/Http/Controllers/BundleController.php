<?php

namespace App\Http\Controllers;

use App\Models\Bundle;
use App\Models\BundleItem;
use App\Models\File;
use App\Models\PasteBin;
use App\Models\ShortURL;
use App\Models\User;
use Hash;
use Illuminate\Http\Request;

class BundleController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:120',
            'description' => 'nullable|string|max:1000',
            'password' => 'nullable|string|max:255',
            'expires' => 'nullable|integer|min:0',
            'items' => 'required|array|min:1',
            'items.*' => 'required|string',
        ]);

        /** @var User */
        $user = $request->user();
        $items = $this->resolveBundleItems($request->input('items', []), $user);

        if (count($items) !== count($request->input('items', []))) {
            return back()->withErrors([
                'items' => 'Only select resources from your account.',
            ]);
        }

        $expiresMins = $request->input('expires');
        $expireDate = $expiresMins ? now()->addMinutes((int) $expiresMins) : null;

        bundleCreation:
        try {
            /** @var Bundle */
            $bundle = Bundle::create([
                'short_code' => $this->generateShortcode(),
                'name' => $request->input('name'),
                'description' => $request->input('description'),
                'password' => $request->filled('password') ? Hash::make($request->input('password')) : null,
                'expires' => $expireDate,
                'user_id' => $user->id,
            ]);
        } catch (\Throwable $th) {
            goto bundleCreation;
        }

        foreach ($items as $position => $item) {
            $bundle->items()->create([
                'resource_type' => $item['type'],
                'resource_id' => $item['id'],
                'position' => $position,
            ]);
        }

        $url = url("/b/$bundle->short_code");

        if ($request->query('_back')) {
            return back()->with('bundle_url', $url);
        }

        return response()->json([
            'url' => $url,
            'short_code' => $bundle->short_code,
        ], 201);
    }

    public function show(Request $request, string $shortCode)
    {
        /** @var Bundle */
        $bundle = Bundle::with('items')
            ->where('short_code', $shortCode)
            ->where(function ($query) {
                $query->whereNull('expires')->orWhere('expires', '>', now());
            })
            ->firstOrFail();

        $password = $request->input('password') ?? $request->input('pwd') ?? null;

        if ($bundle->password && (!$password || !Hash::check($password, $bundle->password))) {
            return view('bundle-password', ['bundle' => $bundle]);
        }

        $items = $bundle->items->filter(fn (BundleItem $item) => $item->isAvailable())->values();

        return view('bundle-show', [
            'bundle' => $bundle,
            'items' => $items,
        ]);
    }

    public function destroy(Request $request, string $shortCode)
    {
        /** @var Bundle */
        $bundle = Bundle::where('short_code', $shortCode)->firstOrFail();

        if ($bundle->user_id !== $request->user()->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $bundle->expire();

        if ($request->query('_back')) {
            return back()->with('bundle_info', 'Bundle deleted.');
        }

        return response()->json(['message' => 'Bundle deleted'], 204);
    }

    private function resolveBundleItems(array $values, User $user): array
    {
        $items = [];

        foreach ($values as $value) {
            if (!preg_match('/^(file|paste|short_url):(\d+)$/', $value, $matches)) {
                continue;
            }

            $type = $matches[1];
            $id = (int) $matches[2];
            $resource = match ($type) {
                BundleItem::TYPE_FILE => File::where('id', $id)->where('user_id', $user->id)->first(),
                BundleItem::TYPE_PASTE => PasteBin::where('id', $id)->where('user_id', $user->id)->first(),
                BundleItem::TYPE_SHORT_URL => ShortURL::where('id', $id)->where('user_id', $user->id)->first(),
                default => null,
            };

            if (!$resource) {
                continue;
            }

            $items[] = [
                'type' => $type,
                'id' => $id,
            ];
        }

        return $items;
    }
}