<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\ShortURLController;
use App\Http\Controllers\PasteBinController;
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

    // Account
    Route::get('/passwordreset', function () {
        return view('auth.passwordreset');
    })->name('password.request');

    Route::get('/resetapi', [AuthController::class, 'resetApiToken'])->name('resetapi');
});


// APIs
// Short URL - s
Route::get('/s/{shortCode}', action: [ShortURLController::class, 'redirect']);
Route::post('/s', [ShortURLController::class, 'store']);

// Short URL - s
Route::get('/f/{shortCode}/delete', [FileController::class, 'destroy']);
Route::delete('/f/{shortCode}', [FileController::class, 'destroy']);
Route::post('/f', [FileController::class, 'store']);
Route::get('/f/{shortCode}', [FileController::class, 'show']);

// Paste Bin routes
Route::post('/p', [PasteBinController::class, 'store']);
Route::get('/p/{shortCode}', [PasteBinController::class, 'show']);
Route::delete('/p/{shortCode}', [PasteBinController::class, 'destroy']);