<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MetricsController extends Controller
{
    /**
     * Health check endpoint.
     */
    public function health(): JsonResponse
    {
        try {
            // Check database connectivity
            $dbHealthy = $this->checkDatabase();

            $health = [
                'status' => $dbHealthy ? 'healthy' : 'unhealthy',
                'checks' => [
                    'database' => $dbHealthy ? 'ok' : 'error',
                ],
                'timestamp' => now()->toIso8601String(),
            ];

            return response()->json($health, $dbHealthy ? 200 : 503);
        } catch (\Throwable $e) {
            Log::error('Health check failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Health check failed',
            ], 503);
        }
    }

    /**
     * Check database connectivity.
     */
    protected function checkDatabase(): bool
    {
        try {
            DB::connection()->getPdo();
            return true;
        } catch (\Throwable $e) {
            Log::error('Database health check failed', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
