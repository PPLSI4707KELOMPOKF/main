<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ChatController;

/*
|--------------------------------------------------------------------------
| Web Routes - LENTRA AI (PBI-1: Chatbot Hukum Lalu Lintas)
|--------------------------------------------------------------------------
*/

// Main chat interface
Route::get('/', [ChatController::class, 'index'])->name('chat.index');

// Chat API routes
Route::post('/chat/send', [ChatController::class, 'sendMessage'])->name('chat.send');
Route::post('/chat/new-session', [ChatController::class, 'newSession'])->name('chat.new-session');
Route::get('/chat/history', [ChatController::class, 'getHistory'])->name('chat.history');
Route::get('/chat/switch/{sessionId}', [ChatController::class, 'switchSession'])->name('chat.switch');
