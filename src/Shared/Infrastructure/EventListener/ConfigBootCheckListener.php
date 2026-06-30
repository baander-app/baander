<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\EventListener;

use App\Shared\Infrastructure\Health\HealthCheckService;
use App\Shared\Infrastructure\Health\HealthStatus;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Logs warnings on first request in each Swoole worker when critical
 * production configs are misconfigured.
 *
 * Runs once per worker (static flag), only in production. Never blocks
 * or throws — warnings only.
 */
final class ConfigBootCheckListener
{
    private static bool $checked = false;

    public function __construct(
        private readonly HealthCheckService $healthCheckService,
        private readonly LoggerInterface $logger,
        private readonly string $appEnv,
    ) {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 1024)]
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        if (self::$checked) {
            return;
        }

        self::$checked = true;

        if ($this->appEnv !== 'prod') {
            return;
        }

        $results = $this->healthCheckService->checkConfiguration();

        foreach ($results as $result) {
            $severity = $result->details['severity'] ?? null;

            if ($severity === 'error' || $result->status === HealthStatus::Unhealthy) {
                $message = $result->details['message'] ?? sprintf('%s check failed.', $result->component);
                $suggestion = $result->details['suggestion'] ?? null;

                $this->logger->warning('[Config] {message}', [
                    'component' => $result->component,
                    'message' => $message,
                    'suggestion' => $suggestion,
                ]);
            }
        }
    }
}
