<?php

namespace App\Http\Controllers;

use App\Http\Middleware\OpenTelemetryMiddleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;

class MetricsController extends Controller
{
    /**
     * Example endpoint demonstrating OpenTelemetry instrumentation.
     */
    public function index(Request $request): JsonResponse
    {
        return OpenTelemetryMiddleware::createSpan(
            'metrics.index',
            function ($span) use ($request) {
                // Add custom attributes to the span
                $span->setAttribute('custom.attribute', 'example-value');
                $span->addEvent('Processing metrics request');

                // Simulate some work with nested spans
                $data = $this->fetchData();
                $processedData = $this->processData($data);

                // Record a custom metric
                $this->recordMetric('request.processed', 1);

                Log::info('Metrics endpoint accessed', [
                    'user_agent' => $request->userAgent(),
                    'ip' => $request->ip(),
                ]);

                return response()->json([
                    'status' => 'success',
                    'data' => $processedData,
                    'timestamp' => now()->toIso8601String(),
                ]);
            },
            [
                'endpoint' => '/api/metrics',
                'method' => 'GET',
            ]
        );
    }

    /**
     * Health check endpoint with minimal overhead.
     */
    public function health(): JsonResponse
    {
        $tracer = app('opentelemetry.tracer');
        $span = $tracer->spanBuilder('health.check')
            ->setSpanKind(SpanKind::KIND_INTERNAL)
            ->startSpan();

        $scope = $span->activate();

        try {
            // Check database connectivity
            $dbHealthy = $this->checkDatabase();

            $health = [
                'status' => $dbHealthy ? 'healthy' : 'unhealthy',
                'checks' => [
                    'database' => $dbHealthy ? 'ok' : 'error',
                    'opentelemetry' => config('opentelemetry.enabled') ? 'enabled' : 'disabled',
                ],
                'timestamp' => now()->toIso8601String(),
            ];

            $span->setAttribute('health.status', $health['status']);
            $span->setStatus(StatusCode::STATUS_OK);

            return response()->json($health, $dbHealthy ? 200 : 503);
        } catch (\Throwable $e) {
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());

            Log::error('Health check failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Health check failed',
            ], 503);
        } finally {
            $span->end();
            $scope->detach();
        }
    }

    /**
     * Demonstrate distributed tracing with database queries.
     */
    public function database(): JsonResponse
    {
        $tracer = app('opentelemetry.tracer');
        $parentSpan = $tracer->spanBuilder('database.operation')
            ->setSpanKind(SpanKind::KIND_INTERNAL)
            ->startSpan();

        $scope = $parentSpan->activate();

        try {
            // Create a child span for the query
            $querySpan = $tracer->spanBuilder('database.query.names')
                ->setSpanKind(SpanKind::KIND_CLIENT)
                ->startSpan();

            $queryScope = $querySpan->activate();

            try {
                $startTime = microtime(true);
                $records = DB::table('names')->get();
                $duration = microtime(true) - $startTime;

                $querySpan->setAttributes([
                    'db.system' => 'postgresql',
                    'db.operation' => 'SELECT',
                    'db.sql.table' => 'names',
                    'db.query.duration_ms' => round($duration * 1000, 2),
                    'db.query.row_count' => $records->count(),
                ]);

                $querySpan->setStatus(StatusCode::STATUS_OK);

                // Record metric for query duration
                $this->recordMetric('database.query.duration', $duration * 1000, [
                    'table' => 'names',
                    'operation' => 'SELECT',
                ]);

                return response()->json([
                    'success' => true,
                    'count' => $records->count(),
                    'duration_ms' => round($duration * 1000, 2),
                    'data' => $records->take(5),
                ]);
            } finally {
                $querySpan->end();
                $queryScope->detach();
            }
        } catch (\Throwable $e) {
            $parentSpan->recordException($e);
            $parentSpan->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());

            Log::error('Database operation failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Database operation failed',
            ], 500);
        } finally {
            $parentSpan->end();
            $scope->detach();
        }
    }

    /**
     * Simulate fetching data with tracing.
     */
    protected function fetchData(): array
    {
        $tracer = app('opentelemetry.tracer');
        $span = $tracer->spanBuilder('fetch.data')
            ->setSpanKind(SpanKind::KIND_INTERNAL)
            ->startSpan();

        $scope = $span->activate();

        try {
            // Simulate some work
            usleep(10000); // 10ms

            $data = [
                'items' => range(1, 10),
                'fetched_at' => now()->toIso8601String(),
            ];

            $span->setAttribute('data.count', count($data['items']));
            $span->addEvent('Data fetched successfully');
            $span->setStatus(StatusCode::STATUS_OK);

            return $data;
        } finally {
            $span->end();
            $scope->detach();
        }
    }

    /**
     * Process data with tracing.
     */
    protected function processData(array $data): array
    {
        $tracer = app('opentelemetry.tracer');
        $span = $tracer->spanBuilder('process.data')
            ->setSpanKind(SpanKind::KIND_INTERNAL)
            ->startSpan();

        $scope = $span->activate();

        try {
            // Simulate processing
            usleep(5000); // 5ms

            $processed = array_map(fn($item) => $item * 2, $data['items']);

            $span->setAttribute('data.processed_count', count($processed));
            $span->addEvent('Data processed', [
                'processing_type' => 'multiplication',
            ]);
            $span->setStatus(StatusCode::STATUS_OK);

            return [
                'original' => $data,
                'processed' => $processed,
            ];
        } finally {
            $span->end();
            $scope->detach();
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

    /**
     * Record a custom metric using OpenTelemetry.
     */
    protected function recordMetric(string $name, float $value, array $attributes = []): void
    {
        try {
            if (!config('opentelemetry.metrics.enabled')) {
                return;
            }

            $meter = app('opentelemetry.meter');

            if (!$meter) {
                return;
            }

            // Create a counter or histogram based on metric type
            if (str_contains($name, 'duration')) {
                $histogram = $meter->createHistogram($name);
                $histogram->record($value, $attributes);
            } else {
                $counter = $meter->createCounter($name);
                $counter->add($value, $attributes);
            }
        } catch (\Throwable $e) {
            Log::debug('Failed to record metric', [
                'metric' => $name,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

