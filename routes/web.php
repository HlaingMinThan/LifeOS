<?php

use App\Http\Controllers\CareController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\IdeaController;
use App\Http\Controllers\InboxController;
use App\Http\Controllers\LedgerController;
use App\Http\Controllers\OnboardController;
use App\Http\Controllers\TodoController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::get('/', [HomeController::class, 'index'])->name('home');

    Route::get('/money', [LedgerController::class, 'index'])->name('money');
    Route::patch('/ledger/{entry}/toggle', [LedgerController::class, 'toggle'])->name('ledger.toggle');
    Route::delete('/ledger/{entry}', [LedgerController::class, 'destroy'])->name('ledger.destroy');

    Route::get('/todos', [TodoController::class, 'index'])->name('todos');
    Route::patch('/todos/{todo}/toggle', [TodoController::class, 'toggle'])->name('todos.toggle');
    Route::delete('/todos/{todo}', [TodoController::class, 'destroy'])->name('todos.destroy');

    Route::get('/care', [CareController::class, 'index'])->name('care');

    Route::get('/ideas', [IdeaController::class, 'index'])->name('ideas');
    Route::delete('/ideas/{idea}', [IdeaController::class, 'destroy'])->name('ideas.destroy');

    // Brain-dump onboarding: paste everything once, review, confirm.
    Route::get('/onboard', [OnboardController::class, 'index'])->name('onboard');
    Route::post('/onboard/dump', [OnboardController::class, 'dump'])->name('onboard.dump');
    Route::post('/onboard/confirm', [OnboardController::class, 'confirm'])->name('onboard.confirm');

    // Magic inbox: parse → confirm chip in the UI → apply → undo.
    Route::post('/inbox/parse', [InboxController::class, 'parse'])->name('inbox.parse');
    Route::post('/inbox/apply', [InboxController::class, 'apply'])->name('inbox.apply');
    Route::post('/inbox/undo/{event}', [InboxController::class, 'undo'])->name('inbox.undo');

    // Starter-kit pages still link to the dashboard route.
    Route::redirect('/dashboard', '/')->name('dashboard');
});

require __DIR__.'/settings.php';
