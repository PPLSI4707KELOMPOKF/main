<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ChatController;

<<<<<<< Updated upstream
Route::get('/', function () {
    return view('welcome');
});
=======
/*
|--------------------------------------------------------------------------
| Web Routes - LENTRA AI
| PBI-1: Chatbot Hukum Lalu Lintas (interface dasar)
| PBI-2: Input Pertanyaan Pengguna (validasi & preprocessing input)
|--------------------------------------------------------------------------
*/

// Main chat interface (PBI-1)
Route::get('/', [ChatController::class, 'index'])->name('chat.index');

// PBI-1: Core chat API
Route::post('/chat/send', [ChatController::class, 'sendMessage'])->name('chat.send');
Route::post('/chat/new-session', [ChatController::class, 'newSession'])->name('chat.new-session');
Route::get('/chat/history', [ChatController::class, 'getHistory'])->name('chat.history');
Route::get('/chat/switch/{sessionId}', [ChatController::class, 'switchSession'])->name('chat.switch');

// PBI-2: Validasi input pertanyaan pengguna (realtime check)
Route::post('/chat/validate-input', [ChatController::class, 'validateInput'])->name('chat.validate-input');
>>>>>>> Stashed changes
