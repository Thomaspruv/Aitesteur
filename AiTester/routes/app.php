<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('dashboard', 'pages::app.dashboard')->name('dashboard');
    Route::livewire('discovery', 'pages::app.discovery')->name('discovery');
    Route::livewire('runs/{run}', 'pages::app.runs.show')->name('runs.show');
    Route::livewire('workflows/{workflow}/edit', 'pages::app.workflows.edit')->name('workflows.edit');
    Route::livewire('workflows/{workflow}/runs', 'pages::app.workflows.runs')->name('workflows.runs');
    Route::livewire('settings-ia', 'pages::app.settings-ai')->name('settings-ai');
    Route::livewire('settings-environnement', 'pages::app.settings-environment')->name('settings-environment');
});
