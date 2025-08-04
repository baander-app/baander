<?php

namespace App\Modules\OpenTelemetry;

use Monolog\Logger;
use OpenTelemetry\API\Globals;
use OpenTelemetry\Contrib\Logs\Monolog\Handler as OpenTelemetryHandler;
use Monolog\Processor\PsrLogMessageProcessor;
use Psr\Log\LogLevel;

class MonologHandler
{
    public function __invoke(array $config)
    {
        $name = $config['name'] ?? 'otel';
        $level = $config['level'] ?? LogLevel::DEBUG;
        $bubble = $config['bubble'] ?? true;

        return $this->make($name, $level, $bubble);
    }

    public static function make(string $name, string $level, bool $bubble)
    {
        $logger = new Logger($name);

        $handler = new OpenTelemetryHandler(
            Globals::loggerProvider(),
            $level,
            $bubble
        );

        $logger->pushHandler($handler);
        $logger->pushProcessor(new PsrLogMessageProcessor());

        return $logger;
    }
}