<?php

namespace App\Http\Controllers;

use App\Models\Directory;
use App\Models\DirectoryItem;
use App\Models\File;
use App\Models\PasteBin;
use App\Models\ShortURL;
use App\Models\UploadLink;
use App\Models\UploadSession;
use App\Models\User;
use App\Support\Thumbnailer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Instance-wide overview and maintenance actions for administrators.
 */
class AdminController extends Controller
{
    public function dashboard()
    {
        return view('admin.dashboard', [
            'stats' => $this->stats(),
            'topUsers' => $this->topUsersByStorage(),
            'popularFiles' => $this->popularFiles(),
            'orphans' => $this->findOrphans(),
        ]);
    }

    /**
     * Headline counts and totals.
     */
    private function stats(): array
    {
        return [
            'users' => User::count(),
            'admins' => User::where('role', User::ROLE_ADMIN)->count(),
            'files' => File::count(),
            'directories' => Directory::count(),
            'pastes' => PasteBin::count(),
            'short_urls' => ShortURL::count(),
            'upload_links' => UploadLink::where('used', false)->count(),
            'in_progress_uploads' => UploadSession::count(),
            'stored_bytes' => (int) File::sum('size') + (int) Directory::sum('size'),
            'downloads' => (int) File::sum('downloads'),
            'redirect_hits' => (int) ShortURL::sum('hits'),
            'expiring_soon' => File::whereNotNull('expires')
                ->whereBetween('expires', [now(), now()->addDay()])
                ->count(),
        ];
    }

    private function topUsersByStorage()
    {
        return User::orderByDesc('storage_used')
            ->limit(10)
            ->get(['id', 'name', 'email', 'storage_used', 'storage_limit', 'role', 'api_token_last_used_at']);
    }

    private function popularFiles()
    {
        return File::where('downloads', '>', 0)
            ->orderByDesc('downloads')
            ->limit(10)
            ->get(['short_code', 'original_name', 'downloads', 'max_downloads', 'size', 'user_id']);
    }

    /**
     * Blobs on disk with no database row pointing at them.
     *
     * These accumulate when a delete half-fails or an upload is interrupted, and
     * nothing else in the application ever looks for them.
     */
    private function findOrphans(): array
    {
        $disk = Storage::disk('local');

        $knownFiles = File::pluck('short_code')->flip();
        $strayFiles = [];
        $strayBytes = 0;

        foreach ($disk->files('files') as $path) {
            $name = basename($path);

            if ($knownFiles->has($name)) {
                continue;
            }

            $strayFiles[] = $name;
            $strayBytes += $disk->size($path);
        }

        $knownItems = DirectoryItem::whereNotNull('storage_path')->pluck('storage_path')->flip();
        $strayDirectoryFiles = 0;

        foreach ($disk->allFiles('directories') as $path) {
            if (!$knownItems->has($path)) {
                $strayDirectoryFiles++;
                $strayBytes += $disk->size($path);
            }
        }

        return [
            'files' => $strayFiles,
            'directory_files' => $strayDirectoryFiles,
            'bytes' => $strayBytes,
        ];
    }

    /**
     * Delete blobs that no database row references.
     */
    public function pruneOrphans(Request $request)
    {
        $disk = Storage::disk('local');
        $removed = 0;
        $reclaimed = 0;

        $knownFiles = File::pluck('short_code')->flip();

        foreach ($disk->files('files') as $path) {
            if ($knownFiles->has(basename($path))) {
                continue;
            }

            $reclaimed += $disk->size($path);
            $disk->delete($path);
            Thumbnailer::delete(basename($path));
            $removed++;
        }

        $knownItems = DirectoryItem::whereNotNull('storage_path')->pluck('storage_path')->flip();

        foreach ($disk->allFiles('directories') as $path) {
            if ($knownItems->has($path)) {
                continue;
            }

            $reclaimed += $disk->size($path);
            $disk->delete($path);
            $removed++;
        }

        return back()->with(
            'account_info',
            "Removed $removed orphaned file(s), reclaiming " . File::reduceFileSize($reclaimed) . '.'
        );
    }
}
