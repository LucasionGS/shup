<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChunkedUploadController;
use App\Http\Controllers\ConfigurationController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\ShortURLController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\PasteBinController;
use App\Http\Controllers\UploadLinkController;
use App\Http\Controllers\DirectoryController;
use Illuminate\Support\Facades\Route;

Route::get('/login', function () {
    return view('auth.login');
})->name('login');
// Throttled: credential stuffing against these was previously unmetered.
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

// POST, not GET: a third-party page could otherwise log the user out with an
// <img> tag, and state changes must not be reachable by cross-site navigation.
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

Route::get('/register', function () {
    return view('auth.register');
})->name('register');

Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1');

// Password reset. Throttled because these endpoints send mail and accept
// guessable tokens.
Route::get('/forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
Route::post('/forgot-password', [PasswordResetController::class, 'sendLink'])
    ->middleware('throttle:6,1')
    ->name('password.email');
Route::get('/reset-password/{token}', [PasswordResetController::class, 'edit'])->name('password.reset');
Route::post('/reset-password', [PasswordResetController::class, 'update'])
    ->middleware('throttle:6,1')
    ->name('password.update');

Route::get('/', function () {
    return redirect(route('dashboard'));
});

Route::middleware(['auth'])->group(function () {
    // Dashboards
    Route::get('/dashboard', function () {
        return view('dashboard');
    })->name('dashboard');

    Route::get('/dashboard/files', function() {
        return view('dashboard.files');
    })->name('files');

    Route::get('/dashboard/shorturls', function() {
        return view('dashboard.shorturls');
    })->name('shorturls');

    Route::get('/dashboard/pastes', function() {
        return view('dashboard.pastes');
    })->name('pastes');

    Route::get('/dashboard/uploadlinks', function() {
        return view('dashboard.uploadlinks');
    })->name('uploadlinks');

    Route::get('/dashboard/directories', function() {
        return view('dashboard.directories');
    })->name('directories');

    Route::get('/profile', function () {
        return view('dashboard.profile');
    })->name('profile');

    // POST for the same reason as /logout: this invalidates the user's API
    // token, so it must not be triggerable by a cross-site GET.
    Route::post('/resetapi', [AuthController::class, 'resetApiToken'])->name('resetapi');

    Route::post('/user', [AuthController::class, 'updateImage'])->name('updateUserImage');
    Route::put('/user/{user}', [AuthController::class, 'update'])->name('updateUser');

});

Route::middleware(['auth', 'isAdmin'])->group(function () {
    // Admin only
    Route::get('/admin', [AdminController::class, 'dashboard'])->name('admin.dashboard');
    Route::post('/admin/prune-orphans', [AdminController::class, 'pruneOrphans'])->name('admin.pruneOrphans');
    Route::get('/admin/users', function () {
        return view('admin.users');
    })->name('admin.users');
    Route::post('/user/invite', [AuthController::class, 'invite'])->name('inviteUser');
    // The admin console has always rendered a delete button for this; it had no
    // route behind it and returned 405.
    Route::delete('/user/{user}', [AuthController::class, 'destroy'])->name('deleteUser');
    Route::post('/configure', [
        ConfigurationController::class, 'store'
    ])->name('configure');
});

// APIs
// Short URL - s
Route::get('/s/{shortCode}', action: [ShortURLController::class, 'redirect']);
Route::post('/s', [ShortURLController::class, 'store']);
Route::delete('/s/{shortCode}', [ShortURLController::class, 'destroy']);

// Resumable uploads. Declared before /f/{shortCode} so "chunk" is not taken
// for a share code.
Route::post('/f/chunk', [ChunkedUploadController::class, 'create']);
Route::get('/f/chunk/{token}', [ChunkedUploadController::class, 'status']);
Route::post('/f/chunk/{token}', [ChunkedUploadController::class, 'append']);
Route::post('/f/chunk/{token}/complete', [ChunkedUploadController::class, 'complete']);
Route::delete('/f/chunk/{token}', [ChunkedUploadController::class, 'destroy']);

// Files - f
// The former GET /f/{shortCode}/delete has been removed: a state-changing GET
// meant any page could delete a file with <img src=".../delete">.
Route::delete('/f/{shortCode}', [FileController::class, 'destroy']);
Route::post('/f', [FileController::class, 'store']);
Route::get('/f/{shortCode}', [FileController::class, 'show'])->middleware('throttle:share');
Route::get('/f/{shortCode}/{filename}', [FileController::class, 'show'])->middleware('throttle:share');

// Paste Bin routes
Route::post('/p', [PasteBinController::class, 'store']);
Route::get('/p/{shortCode}', [PasteBinController::class, 'show'])->middleware('throttle:share');
Route::delete('/p/{shortCode}', [PasteBinController::class, 'destroy']);

// Upload Link routes - ul
Route::post('/ul', [UploadLinkController::class, 'store'])->middleware('auth');
Route::get('/ul/{shortCode}', [UploadLinkController::class, 'show']);
Route::post('/ul/{shortCode}', [UploadLinkController::class, 'upload']);
Route::delete('/ul/{shortCode}', [UploadLinkController::class, 'destroy'])->middleware('auth');

// Directories - d
Route::post('/d', [DirectoryController::class, 'store']);
Route::post('/d/{shortCode}/-/upload', [DirectoryController::class, 'upload']);
Route::post('/d/{shortCode}/-/folders', [DirectoryController::class, 'storeFolder']);
Route::delete('/d/{shortCode}/-/entries', [DirectoryController::class, 'destroyEntry']);
Route::delete('/d/{shortCode}', [DirectoryController::class, 'destroy']);
Route::get('/d/{shortCode}/-/zip/{path?}', [DirectoryController::class, 'zip'])->where('path', '.*');
Route::get('/d/{shortCode}/{path?}', [DirectoryController::class, 'show'])
    ->where('path', '.*')
    ->middleware('throttle:share');