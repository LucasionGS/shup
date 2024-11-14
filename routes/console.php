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
    // $this->comment('Deleted ' . \App\Models\ShortURL::where('expires', '<', now())->delete() . ' expired short URLs');
    foreach ($models as $model) {
        $this->comment('Deleted ' . $model::deleteExpired() . ' expired ' . $model);
    }
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