<?php

use App\Http\Controllers\QualificationController;
use Illuminate\Support\Facades\Route;

Route::middleware('qualification')->group(function (): void {
    Route::post('/workflows', [QualificationController::class, 'start']);
    Route::get('/workflows/{instance}', [QualificationController::class, 'status']);
    Route::get('/workflows/{instance}/query', [QualificationController::class, 'query']);
    Route::post('/workflows/{instance}/signal', [QualificationController::class, 'signal']);
    Route::post('/workflows/{instance}/cancel', [QualificationController::class, 'cancel']);
    Route::post('/workflows/{instance}/request-cancellation', [QualificationController::class, 'requestCancellation']);
});
