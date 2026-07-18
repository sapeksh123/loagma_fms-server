<?php

use App\Http\Controllers\MediaController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Serves uploaded product/category images through Laravel (not as a direct
// public/ static file) so the app's CORS middleware actually applies.
Route::get('/uploads/{path}', [MediaController::class, 'serve'])->where('path', '.*');
