<?php

use App\Http\Controllers\MetricsController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    $record = DB::table('names')->first();

    return response()->json([
        'name' => $record?->name ?? null,
    ]);
});

// OpenTelemetry instrumented endpoints
Route::get('/api/metrics', [MetricsController::class, 'index']);
Route::get('/api/health', [MetricsController::class, 'health']);
Route::get('/api/database', [MetricsController::class, 'database']);
