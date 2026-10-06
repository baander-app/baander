<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Tests\Functional\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class TransportControllerTest extends TestCase
{
    public function testFlushFailedRemovesEveryFailedMessage(): void
    {
        $failureTransport = static::getContainer()->get('messenger.transport.failed');
        self::assertInstanceOf(InMemoryTransport::class, $failureTransport);
        $failureTransport->send(new Envelope(new \stdClass()));
        $failureTransport->send(new Envelope(new \stdClass()));

        $response = $this->authenticatedRequest(
            'POST',
            '/api/monitor/transport/failed/flush?confirm=true',
            $this->createAdminUser(),
        );

        $data = $this->assertJsonResponse($response, 200, 'data');
        self::assertSame(2, $data['data']['flushed']);
        self::assertSame([], iterator_to_array($failureTransport->get()));
    }

    public function testFlushFailedRequiresConfirmation(): void
    {
        $failureTransport = static::getContainer()->get('messenger.transport.failed');
        self::assertInstanceOf(InMemoryTransport::class, $failureTransport);
        $failureTransport->send(new Envelope(new \stdClass()));

        $response = $this->authenticatedRequest(
            'POST',
            '/api/monitor/transport/failed/flush',
            $this->createAdminUser(),
        );

        $this->assertJsonResponse($response, 422);
        self::assertCount(1, iterator_to_array($failureTransport->get()));
    }

    public function testRetryFailedRunsNonInteractivelyAndReportsTheCommandError(): void
    {
        $response = $this->authenticatedRequest(
            'POST',
            '/api/monitor/transport/failed/missing-message/retry',
            $this->createAdminUser(),
        );

        $data = $this->assertJsonResponse($response, 500);
        self::assertStringContainsString(
            'does not support retrying messages by id',
            (string) json_encode($data, JSON_THROW_ON_ERROR),
        );
    }
}
