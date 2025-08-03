<?php

namespace App\Modules\OpenTelemetry;

use OpenTelemetry\Contrib\Logs\Monolog\Handler as OpenTelemetryHandler;
use Monolog\Processor\PsrLogMessageProcessor;

class MonologHandler
{
    public function __invoke(array $config)
    {
        $name = $config['name'] ?? 'otel';
        $level = $config['level'] ?? \Psr\Log\LogLevel::DEBUG;
        $bubble = $config['bubble'] ?? true;

        return $this->make($name, $level, $bubble);
    }

    public static function make(string $name, string $level, bool $bubble)
    {
        $logger = new \Monolog\Logger($name);

        $handler = new OpenTelemetryHandler(
            \OpenTelemetry\API\Globals::loggerProvider(),
            $level,
            $bubble
        );

        $logger->pushHandler($handler);
        $logger->pushProcessor(new PsrLogMessageProcessor());

        return $logger;
    }
}