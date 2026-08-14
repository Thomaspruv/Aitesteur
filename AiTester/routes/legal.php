<?php

use Illuminate\Support\Facades\Route;

Route::view('mentions-legales', 'pages.legal.mentions-legales')->name('legal.mentions');
Route::view('cgu', 'pages.legal.cgu')->name('legal.cgu');
Route::view('confidentialite', 'pages.legal.confidentialite')->name('legal.privacy');
