<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::inertia('/', 'os/Home')->name('home');
    Route::inertia('/money', 'os/Money')->name('money');
    Route::inertia('/todos', 'os/Todos')->name('todos');
    Route::inertia('/care', 'os/Care')->name('care');
    Route::inertia('/ideas', 'os/Ideas')->name('ideas');

    // Starter-kit pages still link to the dashboard route.
    Route::redirect('/dashboard', '/')->name('dashboard');
});

require __DIR__.'/settings.php';
