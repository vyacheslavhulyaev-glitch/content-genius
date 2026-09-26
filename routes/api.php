<?php

use App\Http\Controllers\AdminDashboardController;
use App\Http\Controllers\ContentController;
use App\Http\Controllers\GenerateContentController;
use App\Http\Controllers\RegenerateContentController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return response()->json(['status' => 'ok']);
});

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::get('/contents', [ContentController::class, 'index'])->middleware('auth:sanctum');
Route::get('/admin/dashboard', AdminDashboardController::class)
    ->middleware(['auth:sanctum', 'can:view-admin-dashboard']);
Route::post('/contents', [ContentController::class, 'store'])->middleware('auth:sanctum');
Route::post('/contents/{content}/generate', GenerateContentController::class)->middleware('auth:sanctum');
Route::patch('/contents/{content}', [ContentController::class, 'update'])->middleware('auth:sanctum');
Route::delete('/contents/{content}', [ContentController::class, 'destroy'])->middleware('auth:sanctum');
Route::post('/contents/{content}/regenerate', RegenerateContentController::class)->middleware('auth:sanctum');

Route::get('/test', function () {
    return 'API works';
});
