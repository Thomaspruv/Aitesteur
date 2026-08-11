<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::livewire('dashboard', 'pages::admin.dashboard')->name('dashboard');
    Route::livewire('projects/{project}', 'pages::admin.project')->name('project');
});
