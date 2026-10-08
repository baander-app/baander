<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Interface\Controller;

use App\Shared\Application\DTO\FailedMessage;
use App\Shared\Application\DTO\FailedMessagePage;
use App\Shared\Application\FailedMessageRetryException;
use App\Shared\Application\FailureTransportUnavailableException;
use App\Shared\Application\Port\AsyncTransportUnavailableException;
use App\Shared\Application\Port\FailedMessageAdministrationInterface;
use App\Shared\Application\Port\TransportStatus;
use App\Shared\Application\Port\TransportStatusInterface;
use App\Shared\Interface\Controller\TransportController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class TransportControllerErrorTest extends TestCase
{
    public function testAnUnavailableFailureTransportIsReportedAs503(): void
    {
        $controller = $this->controller(new FailureTransportUnavailableException('connection refused'));

        foreach ([
            $controller->status(),
            $controller->listFailed(new Request()),
            $controller->showFailed('1'),
            $controller->retryFailed('1'),
            $controller->removeFailed('1'),
            $controller->flushFailed(new Request(['confirm' => 'true'])),
        ] as $response) {
            self::assertSame(503, $response->getStatusCode());
            self::assertStringContainsString('Failure transport unavailable: connection refused', (string) $response->getContent());
        }
    }

    public function testAFailedRetryCommandIsReportedAs500WithItsError(): void
    {
        $response = $this->controller(new FailedMessageRetryException('Worker crashed.'))->retryFailed('1');

        self::assertSame(500, $response->getStatusCode());
        self::assertStringContainsString('Failed to retry message: Worker crashed.', (string) $response->getContent());
    }

    public function testAnUnreachableRedisIsReportedAs503(): void
    {
        $response = $this->controller(new AsyncTransportUnavailableException('Redis unavailable: connection refused'))->status();

        self::assertSame(503, $response->getStatusCode());
        self::assertStringContainsString('Redis unavailable: connection refused', (string) $response->getContent());
    }

    private function controller(\RuntimeException $failure): TransportController
    {
        $failedMessages = new readonly class ($failure) implements FailedMessageAdministrationInterface {
            public function __construct(private \RuntimeException $failure)
            {
            }

            public function count(): int
            {
                throw $this->failure;
            }

            public function page(int $page, int $limit): FailedMessagePage
            {
                throw $this->failure;
            }

            public function find(string $id): ?FailedMessage
            {
                throw $this->failure;
            }

            public function retry(string $id): bool
            {
                throw $this->failure;
            }

            public function remove(string $id): bool
            {
                throw $this->failure;
            }

            public function removeAll(): int
            {
                throw $this->failure;
            }
        };

        $transportStatus = new readonly class ($failure) implements TransportStatusInterface {
            public function __construct(private \RuntimeException $failure)
            {
            }

            public function status(): TransportStatus
            {
                throw $this->failure;
            }
        };

        return new TransportController($transportStatus, $failedMessages);
    }
}
