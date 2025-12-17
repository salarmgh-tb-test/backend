<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use Symfony\Component\HttpFoundation\Response;

class OpenTelemetryMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!config('opentelemetry.enabled') || !config('opentelemetry.instrumentation.http')) {
            return $next($request);
        }

        try {
            $tracer = app('opentelemetry.tracer');

            // Extract context from incoming request headers
            $context = $this->extractContext($request);

            // Create a span for this HTTP request
            $span = $tracer
                ->spanBuilder('HTTP ' . $request->method() . ' ' . $request->path())
                ->setSpanKind(SpanKind::KIND_SERVER)
                ->setParent($context)
                ->startSpan();

            // Add HTTP attributes according to OpenTelemetry semantic conventions
            $span->setAttributes([
                'http.method' => $request->method(),
                'http.url' => $request->fullUrl(),
                'http.target' => $request->path(),
                'http.host' => $request->getHost(),
                'http.scheme' => $request->getScheme(),
                'http.user_agent' => $request->userAgent(),
                'http.client_ip' => $request->ip(),
                'http.route' => $request->route()?->uri() ?? $request->path(),
                'net.host.name' => $request->getHost(),
                'net.host.port' => $request->getPort(),
            ]);

            // Add custom attributes
            if ($user = $request->user()) {
                $span->setAttribute('user.id', $user->id);
            }

            if ($requestId = $request->header('X-Request-ID')) {
                $span->setAttribute('request.id', $requestId);
            }

            // Activate the span context
            $scope = $span->activate();

            try {
                // Process the request
                $startTime = microtime(true);
                $response = $next($request);
                $duration = microtime(true) - $startTime;

                // Add response attributes
                $span->setAttributes([
                    'http.status_code' => $response->getStatusCode(),
                    'http.response.body.size' => strlen($response->getContent()),
                    'http.response.duration_ms' => round($duration * 1000, 2),
                ]);

                // Set span status based on response code
                if ($response->getStatusCode() >= 500) {
                    $span->setStatus(StatusCode::STATUS_ERROR, 'Server Error');
                } elseif ($response->getStatusCode() >= 400) {
                    $span->setStatus(StatusCode::STATUS_ERROR, 'Client Error');
                } else {
                    $span->setStatus(StatusCode::STATUS_OK);
                }

                // Add response headers for trace propagation
                $response->headers->set('X-Trace-Id', $span->getContext()->getTraceId());
                $response->headers->set('X-Span-Id', $span->getContext()->getSpanId());

                return $response;
            } catch (\Throwable $e) {
                // Record exception in span
                $span->recordException($e);
                $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());

                throw $e;
            } finally {
                // Always end the span and detach scope
                $span->end();
                $scope->detach();
            }
        } catch (\Throwable $e) {
            // If OpenTelemetry fails, log but continue request processing
            Log::error('OpenTelemetry middleware error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $next($request);
        }
    }

    /**
     * Extract trace context from incoming request headers.
     */
    protected function extractContext(Request $request): Context
    {
        try {
            // Get the global text map propagator
            $propagator = Globals::propagator();

            // Extract context from HTTP headers
            return $propagator->extract($request->headers->all());
        } catch (\Throwable $e) {
            Log::debug('Failed to extract trace context', [
                'error' => $e->getMessage(),
            ]);

            return Context::getCurrent();
        }
    }

    /**
     * Create custom span for a specific operation.
     * This can be used in controllers for fine-grained tracing.
     */
    public static function createSpan(string $name, callable $callback, array $attributes = [])
    {
        if (!config('opentelemetry.enabled')) {
            return $callback();
        }

        try {
            $tracer = app('opentelemetry.tracer');
            $span = $tracer->spanBuilder($name)
                ->setSpanKind(SpanKind::KIND_INTERNAL)
                ->startSpan();

            foreach ($attributes as $key => $value) {
                $span->setAttribute($key, $value);
            }

            $scope = $span->activate();

            try {
                $result = $callback($span);
                $span->setStatus(StatusCode::STATUS_OK);
                return $result;
            } catch (\Throwable $e) {
                $span->recordException($e);
                $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
                throw $e;
            } finally {
                $span->end();
                $scope->detach();
            }
        } catch (\Throwable $e) {
            Log::error('Failed to create custom span', [
                'name' => $name,
                'error' => $e->getMessage(),
            ]);

            return $callback();
        }
    }
}

