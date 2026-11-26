<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Logging;

use App\Shared\Application\LogViewer\TerminalLogGuardInterface;
use Monolog\Handler\HandlerInterface;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Symfony\Bridge\Monolog\Handler\ConsoleHandler;

final class TerminalLogGuard implements TerminalLogGuardInterface
{
    /** @var list<array{Logger, list<HandlerInterface>}> */
    private array $saved = [];
    private bool $active = false;

    /** @param iterable<Logger> $loggers */
    public function __construct(private readonly iterable $loggers)
    {
    }

    public function begin(): void
    {
        if ($this->active) {
            return;
        }
        $this->active = true;
        try {
            foreach ($this->loggers as $logger) {
                $handlers = $logger->getHandlers();
                $this->saved[] = [$logger, $handlers];
                array_filter($handlers, self::keepsFileOutput(...)) |> array_values(...) |> $logger->setHandlers(...);
            }
        } catch (\Throwable $error) {
            $this->end();
            throw $error;
        }
    }

    public function end(): void
    {
        foreach (array_reverse($this->saved) as [$logger, $handlers]) {
            $logger->setHandlers($handlers);
        }
        $this->saved = [];
        $this->active = false;
    }

    private static function keepsFileOutput(HandlerInterface $handler): bool
    {
        return !$handler instanceof ConsoleHandler
            && !($handler instanceof StreamHandler && in_array($handler->getUrl(), ['php://stdout', 'php://stderr'], true));
    }
}
