<?php

return [
    /*
    |--------------------------------------------------------------------------
    | OpenTelemetry Service Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your service. This name will be used to
    | identify your service in traces, metrics, and logs.
    |
    */
    'service_name' => env('OTEL_SERVICE_NAME', 'laravel-backend'),

    /*
    |--------------------------------------------------------------------------
    | OpenTelemetry Service Version
    |--------------------------------------------------------------------------
    |
    | The version of your service. Useful for tracking deployments.
    |
    */
    'service_version' => env('OTEL_SERVICE_VERSION', '1.0.0'),

    /*
    |--------------------------------------------------------------------------
    | OpenTelemetry Environment
    |--------------------------------------------------------------------------
    |
    | The environment your service is running in (dev, staging, production).
    |
    */
    'environment' => env('OTEL_ENVIRONMENT', env('APP_ENV', 'production')),

    /*
    |--------------------------------------------------------------------------
    | OpenTelemetry Enabled
    |--------------------------------------------------------------------------
    |
    | Enable or disable OpenTelemetry instrumentation globally.
    |
    */
    'enabled' => env('OTEL_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | OTLP Exporter Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the OTLP exporter endpoint.
    |
    */
    'exporter' => [
        'otlp' => [
            'endpoint' => env('OTEL_EXPORTER_OTLP_ENDPOINT', 'http://localhost:4318'),
            'protocol' => env('OTEL_EXPORTER_OTLP_PROTOCOL', 'http/protobuf'),
            'headers' => env('OTEL_EXPORTER_OTLP_HEADERS', ''),
            'timeout' => env('OTEL_EXPORTER_OTLP_TIMEOUT', 10),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Trace Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for trace collection and sampling.
    |
    */
    'traces' => [
        'enabled' => env('OTEL_TRACES_ENABLED', true),
        'sampler' => env('OTEL_TRACES_SAMPLER', 'always_on'), // always_on, always_off, traceidratio, parentbased_always_on
        'sampler_arg' => env('OTEL_TRACES_SAMPLER_ARG', 1.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for metrics collection.
    |
    */
    'metrics' => [
        'enabled' => env('OTEL_METRICS_ENABLED', true),
        'export_interval' => env('OTEL_METRIC_EXPORT_INTERVAL', 60000), // milliseconds
    ],

    /*
    |--------------------------------------------------------------------------
    | Logs Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for logs export via OpenTelemetry.
    |
    */
    'logs' => [
        'enabled' => env('OTEL_LOGS_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Resource Attributes
    |--------------------------------------------------------------------------
    |
    | Additional resource attributes to include with telemetry data.
    |
    */
    'resource_attributes' => [
        'deployment.environment' => env('OTEL_ENVIRONMENT', env('APP_ENV', 'production')),
        'service.namespace' => env('OTEL_SERVICE_NAMESPACE', 'default'),
        'service.instance.id' => env('HOSTNAME', gethostname()),
        'host.name' => gethostname(),
    ],

    /*
    |--------------------------------------------------------------------------
    | Propagators
    |--------------------------------------------------------------------------
    |
    | Configure which context propagators to use.
    | Options: tracecontext, baggage, b3, b3multi, jaeger, xray, ottrace
    |
    */
    'propagators' => env('OTEL_PROPAGATORS', 'tracecontext,baggage'),

    /*
    |--------------------------------------------------------------------------
    | Instrumentation Configuration
    |--------------------------------------------------------------------------
    |
    | Enable or disable specific auto-instrumentation features.
    |
    */
    'instrumentation' => [
        'http' => env('OTEL_INSTRUMENTATION_HTTP_ENABLED', true),
        'database' => env('OTEL_INSTRUMENTATION_DATABASE_ENABLED', true),
        'cache' => env('OTEL_INSTRUMENTATION_CACHE_ENABLED', true),
        'queue' => env('OTEL_INSTRUMENTATION_QUEUE_ENABLED', true),
        'redis' => env('OTEL_INSTRUMENTATION_REDIS_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Spans Configuration
    |--------------------------------------------------------------------------
    |
    | Configure which parts of your application should create custom spans.
    |
    */
    'custom_spans' => [
        'queries' => env('OTEL_SPAN_QUERIES', true),
        'views' => env('OTEL_SPAN_VIEWS', true),
        'events' => env('OTEL_SPAN_EVENTS', true),
    ],
];

