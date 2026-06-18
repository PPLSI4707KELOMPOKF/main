<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Admin\RegulationDocumentController;
use App\Http\Controllers\ChatController;

/*
|--------------------------------------------------------------------------
| Web Routes - LENTRA AI
|--------------------------------------------------------------------------
| PBI-1: Chatbot Hukum Lalu Lintas (interface dasar)
| PBI-2: Input Pertanyaan Pengguna (validasi & preprocessing input)
| Rate Limiting: 10 req/menit untuk chat, 30 req/menit untuk session
|--------------------------------------------------------------------------
*/

// Main chat interface (PBI-1)
Route::get('/', [ChatController::class, 'index'])->name('chat.index');
Route::view('/panduan-info', 'guide.index')->name('guide.index');
Route::view('/tentang-lentra-ai', 'about.index')->name('about.index');

// Lightweight session auth
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.store');
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->name('register.store');
});

Route::post('/logout', [AuthController::class, 'logout'])
    ->middleware('auth')
    ->name('logout');

Route::middleware(['auth', 'admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::resource('regulations', RegulationDocumentController::class)
            ->except(['show'])
            ->parameters(['regulations' => 'regulation']);
    });

// Chat API routes — rate limiting 10 req/menit (anti-spam)
Route::middleware(['throttle:10,1'])->group(function () {
    Route::post('/chat/send', [ChatController::class, 'sendMessage'])->name('chat.send');
    Route::get('/chat/stream', [ChatController::class, 'streamMessage'])->name('chat.stream');
    Route::post('/chat/validate-input', [ChatController::class, 'validateInput'])->name('chat.validate-input');
});


// Session management — rate limit lebih longgar (30 req/menit)
Route::middleware(['auth', 'throttle:30,1'])->group(function () {
    Route::post('/chat/new-session', [ChatController::class, 'newSession'])->name('chat.new-session');
    Route::get('/chat/history', [ChatController::class, 'getHistory'])->name('chat.history');
    Route::get('/chat/switch/{sessionId}', [ChatController::class, 'switchSession'])->name('chat.switch');
});

// Ollama status check (tidak perlu rate limit ketat)
Route::get('/chat/status', [ChatController::class, 'checkStatus'])->name('chat.status');
