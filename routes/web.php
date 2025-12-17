<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ConfigurationController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\ShortURLController;
use App\Http\Controllers\PasteBinController;
use App\Http\Controllers\UploadLinkController;
use Illuminate\Support\Facades\Route;

Route::get('/login', function () {
    return view('auth.login');
})->name('login');
Route::post('/login', [AuthController::class, 'login']);

Route::get('/logout', [AuthController::class, 'logout'])->name('logout');

Route::get('/register', function () {
    return view('auth.register');
})->name('register');

Route::post('/register', [AuthController::class, 'register']);

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

    // Account
    Route::get('/passwordreset', function () {
        return view('auth.passwordreset');
    })->name('password.request');

    Route::get('/resetapi', [AuthController::class, 'resetApiToken'])->name('resetapi');

    Route::post('/user', [AuthController::class, 'updateImage'])->name('updateUserImage');
    Route::put('/user/{user}', [AuthController::class, 'update'])->name('updateUser');

    Route::post('/configure', [
        ConfigurationController::class, 'store'
      ])->name('configure');
});

Route::middleware(['auth', 'isAdmin'])->group(function () {
    // Admin only
    Route::get('/admin/users', function () {
        return view('admin.users');
    })->name('admin.users');
    Route::post('/user/invite', [AuthController::class, 'invite'])->name('inviteUser');
});

// APIs
// Short URL - s
Route::get('/s/{shortCode}', action: [ShortURLController::class, 'redirect']);
Route::post('/s', [ShortURLController::class, 'store']);
Route::delete('/s/{shortCode}', [ShortURLController::class, 'destroy']);

// Files - f
Route::get('/f/{shortCode}/delete', [FileController::class, 'destroy']);
Route::delete('/f/{shortCode}', [FileController::class, 'destroy']);
Route::post('/f', [FileController::class, 'store']);
Route::get('/f/{shortCode}', [FileController::class, 'show']);
Route::get('/f/{shortCode}/{filename}', [FileController::class, 'show']);

// Paste Bin routes
Route::post('/p', [PasteBinController::class, 'store']);
Route::get('/p/{shortCode}', [PasteBinController::class, 'show']);
Route::delete('/p/{shortCode}', [PasteBinController::class, 'destroy']);

// Upload Link routes - ul
Route::post('/ul', [UploadLinkController::class, 'store'])->middleware('auth');
Route::get('/ul/{shortCode}', [UploadLinkController::class, 'show']);
Route::post('/ul/{shortCode}', [UploadLinkController::class, 'upload']);
Route::delete('/ul/{shortCode}', [UploadLinkController::class, 'destroy'])->middleware('auth');