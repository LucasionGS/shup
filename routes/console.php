<?php

use App\Models\User;
use App\Support\UpdateChecker;
use Illuminate\Support\Facades\Artisan;

Artisan::command('shup:expired', function () {
    $this->comment('Deleting expired items');
    /**
     * @var array<\App\Expireable> $models
     */
    $models = [
        \App\Models\File::class,
        \App\Models\PasteBin::class,
        \App\Models\ShortURL::class,
        \App\Models\Directory::class,
        // Abandoned resumable uploads, so partial files are not left on disk.
        \App\Models\UploadSession::class,
    ];

    foreach ($models as $model) {
        $this->comment('Deleted ' . $model::deleteExpired() . ' expired ' . $model);
    }

    // Delete expired invites
    $this->comment('Deleted ' . \App\Models\InvitedUsers::where('expires_at', '<', now())->delete() . ' expired invites');
})->purpose('Runs through all the expirable items and deletes them')->everyMinute();

Artisan::command('shup:check_updates {--force} {--fake}', function () {
    $status = UpdateChecker::check((bool) $this->option('force') || $this->option('fake'));
    
    if ($this->option('fake')) {
        $status["branch"] ??= "BRANCH";
        $status["behind"] ??= 0;
        $status["upstream"] ??= "UPSTREAM";
    }
    
    if ($this->option('fake') || ($status['available'] ?? false)) {
        $this->comment("Update available: {$status['branch']} is {$status['behind']} commit(s) behind {$status['upstream']}.");
        return;
    }

    $this->comment('No update available. Status: ' . ($status['reason'] ?? 'unknown'));
})->purpose('Check whether the current git branch is behind its upstream')->hourly()->withoutOverlapping();

Artisan::command('shup:signup {action}', function ($action) {
    if ($action === 'enable') {
        $this->comment('Allowing signups');
        \App\Models\Configuration::set('allow_signup', true);
    } elseif ($action === 'disable') {
        $this->comment('Disallowing signups');
        \App\Models\Configuration::set('allow_signup', false);
    } else {
        $this->comment('Invalid action. Use "enable" or "disable".');
    }
})->purpose('Enable or disable signups');

Artisan::command('shup:anonymous_upload {action}', function ($action) {
    if ($action === 'enable') {
        $this->comment('Allowing anonymous uploads');
        \App\Models\Configuration::set('allow_anonymous_upload', true);
    } elseif ($action === 'disable') {
        $this->comment('Disallowing anonymous uploads');
        \App\Models\Configuration::set('allow_anonymous_upload', false);
    } else {
        $this->comment('Invalid action. Use "enable" or "disable".');
    }
})->purpose('Enable or disable anonymous uploads');

Artisan::command("shup:recalculate_storage", function () {
    $this->comment('Recalculating storage for all users');
    User::all()->each->calculateStorage();
    $this->comment('Done');
})->purpose('Recalculate storage for all users')->daily();

Artisan::command("shup:recalculate_physical_storage", function () {
    $this->comment('Recalculating physical storage for all content...');
    
    
    // \App\Models\File::class;
    // Chunked rather than all(), and tolerant of a row whose blob is gone:
    // filesize() on a missing file raised an error that aborted the entire
    // run, leaving every later item unprocessed.
    $file_count = \App\Models\File::count();
    $processed = 0;
    $missing = [];

    \App\Models\File::orderBy('id')->chunkById(200, function ($files) use (&$processed, &$missing, $file_count) {
        foreach ($files as $file) {
            $processed++;
            $file_path = $file->absolutePath();

            clearstatcache(true, $file_path);

            if (!is_file($file_path)) {
                $missing[] = $file->short_code;
                continue;
            }

            $file->size = filesize($file_path) ?: 0;
            $file->save();
        }

        $this->comment("Processed {$processed}/{$file_count} files");
    });

    if ($missing !== []) {
        $this->newLine();
        $this->warn(count($missing) . ' file record(s) have no file on disk and were skipped:');

        foreach (array_slice($missing, 0, 20) as $short_code) {
            $this->line("  $short_code");
        }

        if (count($missing) > 20) {
            $this->line('  ... and ' . (count($missing) - 20) . ' more');
        }

        $this->newLine();
        $this->line('These share links will return 404. Either the blobs were lost, or');
        $this->line('storage was not fully copied. Their sizes were left unchanged.');
        $this->newLine();
    }

    // \App\Models\PasteBin::class;
    $paste_bins = \App\Models\PasteBin::all();
    $paste_bin_count = $paste_bins->count();
    for ($i = 0; $i < $paste_bin_count; $i++) {
        $paste_bin = $paste_bins[$i];
        $rI = $i + 1;
        $this->comment("Processing paste bin {$rI}/{$paste_bin_count}");
        $paste_bin->size = strlen($paste_bin->content);
        $paste_bin->save();
        $this->comment("Paste bin size updated to {$paste_bin->size} bytes");
    }

    // \App\Models\ShortURL::class;
    $short_urls = \App\Models\ShortURL::all();
    $short_url_count = $short_urls->count();
    for ($i = 0; $i < $short_url_count; $i++) {
        $short_url = $short_urls[$i];
        $rI = $i + 1;
        $this->comment("Processing short URL {$rI}/{$short_url_count}");
        $short_url->size = strlen($short_url->url);
        $short_url->save();
        $this->comment("Short URL size updated to {$short_url->size} bytes");
    }

    $directories = \App\Models\Directory::with('files')->get();
    $directory_count = $directories->count();
    for ($i = 0; $i < $directory_count; $i++) {
        $directory = $directories[$i];
        $rI = $i + 1;
        $this->comment("Processing directory {$rI}/{$directory_count}");
        $directory->size = $directory->files->sum('size');
        $directory->save();
        $this->comment("Directory size updated to {$directory->size} bytes");
    }

    $this->comment('Recalculating user totals...');
    Artisan::call('shup:recalculate_storage');
    
    $this->comment('Done');
})->purpose('Recalculate storage for all users')->daily();

Artisan::command("shup:role {email?} {role?}", function ($email = null, $role = null) {

    if ($email === null) {
        $this->comment('Valid roles:');
        foreach (User::$roles as $key => $value) {
            $this->comment("{$key}: {$value}");
        }
        return;
    }
    
    $user = User::where('email', $email)->first();
    if ($user === null) {
        $this->comment('User not found');
        return;
    }

    if ($role === null) {
        $this->comment("Current role: {$user->getRoleName()} ($user->role)");
        return;
    }
    
    if (is_numeric($role)) {
        if (!array_key_exists($role, User::$roles)) {
            $this->comment('Invalid role');
            return;
        }
    }
    else {
        $role = array_search($role, User::$roles);
        if ($role === false) {
            $this->comment('Invalid role');
            return;
        }
    }
    
    $user->role = $role;
    $user->save();
    $this->comment('Role updated to ' . $user->getRoleName());
})->purpose('Update a user\'s role');