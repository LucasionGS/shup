<?php

use App\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

Artisan::command('su:expired', function () {
    $this->comment('Deleting expired items');
    /**
     * @var array<\App\Expireable> $models
     */
    $models = [
        \App\Models\File::class,
        \App\Models\PasteBin::class,
        \App\Models\ShortURL::class,
    ];

    foreach ($models as $model) {
        $this->comment('Deleted ' . $model::deleteExpired() . ' expired ' . $model);
    }

    // Delete expired invites
    $this->comment('Deleted ' . \App\Models\InvitedUsers::where('expires_at', '<', now())->delete() . ' expired invites');
})->purpose('Runs through all the expirable items and deletes them')->everyMinute();

Artisan::command('su:signup {action}', function ($action) {
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

Artisan::command('su:anonymous_upload {action}', function ($action) {
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

Artisan::command("su:recalculate_storage", function () {
    $this->comment('Recalculating storage for all users');
    User::all()->each->calculateStorage();
    $this->comment('Done');
})->purpose('Recalculate storage for all users')->daily();

Artisan::command("su:recalculate_physical_storage", function () {
    $this->comment('Recalculating physical storage for all content...');
    
    
    // \App\Models\File::class;
    $files = \App\Models\File::all();
    $file_count = $files->count();
    for ($i = 0; $i < $file_count; $i++) {
        $file = $files[$i];
        $rI = $i + 1;
        $this->comment("Processing file {$rI}/{$file_count}");
        $file_path = "app/private/files/$file->short_code";
        $file_path = storage_path($file_path);
        $file_size = filesize($file_path);

        $file->size = $file_size;
        $file->save();
        $this->comment("File size updated to {$file_size} bytes");
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

    $this->comment('Recalculating user totals...');
    Artisan::call('su:recalculate_storage');
    
    $this->comment('Done');
})->purpose('Recalculate storage for all users')->daily();

Artisan::command("su:role {email?} {role?}", function ($email = null, $role = null) {

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