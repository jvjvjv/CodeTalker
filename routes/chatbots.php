<?php

use Jvjvjv\CodeTalker\Http\Controllers\ChatBotController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Chat Bot Routes
|--------------------------------------------------------------------------
|
| Routes for public-facing chat bot interfaces. The root-level wildcard
| ({aiChatBot:slug}) must remain at the end of this file so it does not
| swallow more-specific literal routes registered in web.php.
|
*/

Route::middleware(config('code-talker.middleware', ['web']))
    ->get('/chats', [ChatBotController::class, 'index'])
    ->name('chat-bots.index');

Route::middleware(config('code-talker.middleware', ['web']))
    ->get('/chats/statuses', [ChatBotController::class, 'statuses'])
    ->name('chat-bots.statuses');

// Hash/UUID-based conversation links — a single static route replaces the old
// per-bot dynamic routes registered in RouteServiceProvider.
// The bot is resolved from the conversation; the slug is informational only.
Route::middleware(config('code-talker.middleware', ['web']))
    ->prefix('chat')
    ->group(function () {
        Route::get('/{slug}/{hash}', [ChatBotController::class, 'showByHash'])
            ->where('hash', '[a-f0-9]{32}')
            ->name('chat-bot.by-hash');
    });

// Shared route group for chat bot endpoints (used in two places below)
$chatBotRoutes = function () {
    Route::get('/{aiChatBot:slug}', [ChatBotController::class, 'show'])->name('show');
    Route::get('/{aiChatBot:slug}/new', [ChatBotController::class, 'newChat'])->name('new');
    Route::get('/{aiChatBot:slug}/status', [ChatBotController::class, 'status'])->name('status');
    Route::post('/{aiChatBot:slug}/warmup', [ChatBotController::class, 'warmup'])->name('warmup');
    Route::post('/{aiChatBot:slug}/messages', [ChatBotController::class, 'message'])->name('message');
    Route::post('/{aiChatBot:slug}/reset', [ChatBotController::class, 'reset'])->name('reset');
    Route::post('/{aiChatBot:slug}/switch', [ChatBotController::class, 'switch'])->name('switch');
};

// /chat/{slug} prefixed routes
Route::middleware(config('code-talker.middleware', ['web']))
    ->prefix('chat')
    ->name('chat-bots.chat.')
    ->group($chatBotRoutes);

// Root-level /{slug} routes — must be last so the wildcard does not match
// literal paths registered before this file is loaded.
Route::middleware(config('code-talker.middleware', ['web']))
    ->name('chat-bots.root.')
    ->group($chatBotRoutes);
