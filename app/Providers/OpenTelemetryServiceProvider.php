<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Log;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\Contrib\Otlp\SpanExporter as OtlpSpanExporter;
use OpenTelemetry\SDK\Common\Export\Http\PsrTransportFactory;
use OpenTelemetry\API\Common\Instrumentation\Globals as InstrumentationGlobals;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\Contrib\Otlp\MetricExporter as OtlpMetricExporter;
use OpenTelemetry\SDK\Logs\LoggerProvider;
use OpenTelemetry\SDK\Logs\Processor\SimpleLogsProcessor;
use OpenTelemetry\Contrib\Otlp\LogsExporter as OtlpLogsExporter;

class OpenTelemetryServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(TracerProviderInterface::class, function ($app) {
            return $this->createTracerProvider();
        });

        $this->app->singleton('opentelemetry.tracer', function ($app) {
            return $app->make(TracerProviderInterface::class)
                ->getTracer(
                    config('opentelemetry.service_name'),
                    config('opentelemetry.service_version')
                );
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        if (!config('opentelemetry.enabled', true)) {
            return;
        }

        try {
            // Initialize Tracer Provider
            $tracerProvider = $this->app->make(TracerProviderInterface::class);
            Globals::registerInitialTraceProvider($tracerProvider);

            // Initialize Metrics if enabled
            if (config('opentelemetry.metrics.enabled', true)) {
                $this->initializeMetrics();
            }

            // Initialize Logs if enabled
            if (config('opentelemetry.logs.enabled', true)) {
                $this->initializeLogs();
            }

            // Listen to application events for custom spans
            $this->registerEventListeners();

            // Log successful initialization
            Log::info('OpenTelemetry initialized successfully', [
                'service' => config('opentelemetry.service_name'),
                'environment' => config('opentelemetry.environment'),
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to initialize OpenTelemetry', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Create and configure the tracer provider.
     */
    protected function createTracerProvider(): TracerProviderInterface
    {
        $resource = $this->createResource();

        $exporter = $this->createSpanExporter();

        // Use batch processor in production, simple processor in development
        $spanProcessor = app()->environment('local')
            ? new SimpleSpanProcessor($exporter)
            : new BatchSpanProcessor($exporter);

        return TracerProvider::builder()
            ->addSpanProcessor($spanProcessor)
            ->setResource($resource)
            ->setSampler($this->createSampler())
            ->build();
    }

    /**
     * Create resource information for telemetry.
     */
    protected function createResource(): ResourceInfo
    {
        $attributes = Attributes::create(
            array_merge([
                'service.name' => config('opentelemetry.service_name'),
                'service.version' => config('opentelemetry.service_version'),
                'deployment.environment' => config('opentelemetry.environment'),
            ], config('opentelemetry.resource_attributes', []))
        );

        return ResourceInfo::create($attributes);
    }

    /**
     * Create span exporter based on configuration.
     */
    protected function createSpanExporter(): OtlpSpanExporter
    {
        $endpoint = config('opentelemetry.exporter.otlp.endpoint') . '/v1/traces';

        return new OtlpSpanExporter(
            PsrTransportFactory::discover()->create($endpoint, 'application/x-protobuf')
        );
    }

    /**
     * Create sampler based on configuration.
     */
    protected function createSampler()
    {
        $samplerType = config('opentelemetry.traces.sampler', 'always_on');

        return match ($samplerType) {
            'always_off' => new \OpenTelemetry\SDK\Trace\Sampler\AlwaysOffSampler(),
            'traceidratio' => new \OpenTelemetry\SDK\Trace\Sampler\TraceIdRatioBasedSampler(
                (float) config('opentelemetry.traces.sampler_arg', 1.0)
            ),
            'parentbased_always_on' => new \OpenTelemetry\SDK\Trace\Sampler\ParentBased(
                new \OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler()
            ),
            default => new \OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler(),
        };
    }

    /**
     * Initialize metrics collection.
     */
    protected function initializeMetrics(): void
    {
        try {
            $endpoint = config('opentelemetry.exporter.otlp.endpoint') . '/v1/metrics';

            $exporter = new OtlpMetricExporter(
                PsrTransportFactory::discover()->create($endpoint, 'application/x-protobuf')
            );

            $reader = new ExportingReader($exporter);

            $meterProvider = MeterProvider::builder()
                ->setResource($this->createResource())
                ->addReader($reader)
                ->build();

            // Register the meter provider globally
            $this->app->singleton('opentelemetry.meter', function () use ($meterProvider) {
                return $meterProvider->getMeter(
                    config('opentelemetry.service_name'),
                    config('opentelemetry.service_version')
                );
            });

            Log::debug('OpenTelemetry Metrics initialized');
        } catch (\Throwable $e) {
            Log::error('Failed to initialize OpenTelemetry Metrics', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Initialize logs export.
     */
    protected function initializeLogs(): void
    {
        try {
            $endpoint = config('opentelemetry.exporter.otlp.endpoint') . '/v1/logs';

            $exporter = new OtlpLogsExporter(
                PsrTransportFactory::discover()->create($endpoint, 'application/x-protobuf')
            );

            $loggerProvider = LoggerProvider::builder()
                ->setResource($this->createResource())
                ->addLogRecordProcessor(new SimpleLogsProcessor($exporter))
                ->build();

            $this->app->singleton('opentelemetry.logger', function () use ($loggerProvider) {
                return $loggerProvider->getLogger(
                    config('opentelemetry.service_name'),
                    config('opentelemetry.service_version')
                );
            });

            Log::debug('OpenTelemetry Logs initialized');
        } catch (\Throwable $e) {
            Log::error('Failed to initialize OpenTelemetry Logs', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Register event listeners for custom instrumentation.
     */
    protected function registerEventListeners(): void
    {
        // Listen to database queries
        if (config('opentelemetry.custom_spans.queries', true)) {
            $this->app['events']->listen(\Illuminate\Database\Events\QueryExecuted::class, function ($query) {
                // This will be handled by the middleware, but we could add custom attributes here
                Log::debug('Query executed', [
                    'sql' => $query->sql,
                    'time' => $query->time,
                ]);
            });
        }

        // Listen to view rendering
        if (config('opentelemetry.custom_spans.views', true)) {
            $this->app['events']->listen('composing:*', function ($view) {
                Log::debug('View rendering', ['view' => $view]);
            });
        }
    }
}

