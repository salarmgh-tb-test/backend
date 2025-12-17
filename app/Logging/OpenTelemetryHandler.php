<?php

namespace App\Logging;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Logs\LogRecord as OtelLogRecord;
use OpenTelemetry\API\Logs\Severity;

class OpenTelemetryHandler extends AbstractProcessingHandler
{
    /**
     * Create a new OpenTelemetry handler instance.
     */
    public function __construct(int $level = Logger::DEBUG, bool $bubble = true)
    {
        parent::__construct($level, $bubble);
    }

    /**
     * Write the log record to OpenTelemetry.
     */
    protected function write(LogRecord $record): void
    {
        if (!config('opentelemetry.enabled') || !config('opentelemetry.logs.enabled')) {
            return;
        }

        try {
            $logger = app('opentelemetry.logger');

            if (!$logger) {
                return;
            }

            // Convert Monolog level to OpenTelemetry severity
            $severity = $this->convertSeverity($record->level);

            // Get current span context if available
            $span = Globals::tracerProvider()
                ->getTracer(config('opentelemetry.service_name'))
                ->spanBuilder('log')
                ->startSpan();

            $spanContext = $span->getContext();

            // Create OpenTelemetry log record
            $logRecord = (new OtelLogRecord())
                ->setSeverityNumber($severity)
                ->setSeverityText($record->level->getName())
                ->setBody($record->message)
                ->setAttributes([
                    'log.channel' => $record->channel,
                    'log.level' => $record->level->getName(),
                    'trace_id' => $spanContext->getTraceId(),
                    'span_id' => $spanContext->getSpanId(),
                ])
                ->setTimestamp((int) ($record->datetime->getTimestamp() * 1_000_000_000));

            // Add context data as attributes
            if (!empty($record->context)) {
                foreach ($record->context as $key => $value) {
                    if (is_scalar($value) || is_null($value)) {
                        $logRecord->setAttributes(['context.' . $key => $value]);
                    }
                }
            }

            // Add extra data as attributes
            if (!empty($record->extra)) {
                foreach ($record->extra as $key => $value) {
                    if (is_scalar($value) || is_null($value)) {
                        $logRecord->setAttributes(['extra.' . $key => $value]);
                    }
                }
            }

            $logger->emit($logRecord);
            $span->end();
        } catch (\Throwable $e) {
            // Silently fail to avoid breaking application
            error_log('OpenTelemetry logging failed: ' . $e->getMessage());
        }
    }

    /**
     * Convert Monolog log level to OpenTelemetry severity.
     */
    protected function convertSeverity(Logger $level): Severity
    {
        return match ($level->value) {
            Logger::DEBUG => Severity::DEBUG,
            Logger::INFO => Severity::INFO,
            Logger::NOTICE => Severity::INFO2,
            Logger::WARNING => Severity::WARN,
            Logger::ERROR => Severity::ERROR,
            Logger::CRITICAL => Severity::FATAL,
            Logger::ALERT => Severity::FATAL2,
            Logger::EMERGENCY => Severity::FATAL3,
            default => Severity::INFO,
        };
    }
}

