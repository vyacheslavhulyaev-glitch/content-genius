<?php

use App\Http\Controllers\AIController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/ai', AIController::class);

Route::post('/login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:6,1');
Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->middleware('auth:web');
