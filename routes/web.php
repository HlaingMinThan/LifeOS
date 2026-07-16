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
    Route::inertia('/profile', 'os/Profile')->name('profile');

    Route::get('/money', [LedgerController::class, 'index'])->name('money');
    Route::post('/ledger', [LedgerController::class, 'store'])->name('ledger.store');
    Route::patch('/ledger/{entry}/toggle', [LedgerController::class, 'toggle'])->name('ledger.toggle');
    Route::patch('/ledger/{entry}', [LedgerController::class, 'update'])->name('ledger.update');
    Route::delete('/ledger/{entry}', [LedgerController::class, 'destroy'])->name('ledger.destroy');

    Route::get('/todos', [TodoController::class, 'index'])->name('todos');
    Route::get('/todos/{todo}', [TodoController::class, 'show'])->whereNumber('todo')->name('todos.show');
    Route::get('/todos/day/{date}', [TodoController::class, 'day'])->name('todos.day');
    Route::post('/todos', [TodoController::class, 'store'])->name('todos.store');
    Route::patch('/todos/{todo}/toggle', [TodoController::class, 'toggle'])->name('todos.toggle');
    Route::patch('/todos/{todo}/focus', [TodoController::class, 'focus'])->name('todos.focus');
    Route::patch('/todos/{todo}', [TodoController::class, 'update'])->name('todos.update');
    Route::delete('/todos/{todo}', [TodoController::class, 'destroy'])->name('todos.destroy');

    Route::get('/care', [CareController::class, 'index'])->name('care');
    Route::post('/care', [CareController::class, 'store'])->name('care.store');
    Route::patch('/care/{task}/toggle', [CareController::class, 'toggle'])->name('care.toggle');
    Route::patch('/care/{task}', [CareController::class, 'update'])->name('care.update');
    Route::delete('/care/{task}', [CareController::class, 'destroy'])->name('care.destroy');

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

require __DIR__ . '/settings.php';
