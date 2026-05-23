<?php

use Illuminate\Support\Facades\Route;
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

// Chat API routes — rate limiting 10 req/menit (anti-spam)
Route::middleware(['throttle:10,1'])->group(function () {
    Route::post('/chat/send', [ChatController::class, 'sendMessage'])->name('chat.send');
    Route::get('/chat/stream', [ChatController::class, 'streamMessage'])->name('chat.stream');
    Route::post('/chat/validate-input', [ChatController::class, 'validateInput'])->name('chat.validate-input');
});


// Session management — rate limit lebih longgar (30 req/menit)
Route::middleware(['throttle:30,1'])->group(function () {
    Route::post('/chat/new-session', [ChatController::class, 'newSession'])->name('chat.new-session');
    Route::get('/chat/history', [ChatController::class, 'getHistory'])->name('chat.history');
    Route::get('/chat/switch/{sessionId}', [ChatController::class, 'switchSession'])->name('chat.switch');
});

// Ollama status check (tidak perlu rate limit ketat)
Route::get('/chat/status', [ChatController::class, 'checkStatus'])->name('chat.status');
