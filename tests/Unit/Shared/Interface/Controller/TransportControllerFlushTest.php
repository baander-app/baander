<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Interface\Controller;

use App\Shared\Infrastructure\Redis\RedisClientFactory;
use App\Shared\Interface\Controller\TransportController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;

final class TransportControllerFlushTest extends TestCase
{
    public function testFlushContinuesPastMessagesTheReceiverCannotDecode(): void
    {
        $receiver = new class implements ReceiverInterface {
            /** @var list<Envelope|MessageDecodingFailedException> */
            public array $pending;

            /** @var list<Envelope> */
            public array $rejected = [];

            public function __construct()
            {
                $this->pending = [
                    new Envelope(new \stdClass()),
                    new MessageDecodingFailedException('Unknown message class.'),
                    new Envelope(new \stdClass()),
                ];
            }

            public function get(): iterable
            {
                $next = array_shift($this->pending);
                if ($next instanceof MessageDecodingFailedException) {
                    throw $next;
                }

                return $next === null ? [] : [$next];
            }

            public function ack(Envelope $envelope): void
            {
                throw new \LogicException('Flushing must not acknowledge messages.');
            }

            public function reject(Envelope $envelope): void
            {
                $this->rejected[] = $envelope;
            }
        };
        $controller = new TransportController(new RedisClientFactory('redis://redis.baander.app:6379'), 'test-consumer', $receiver);

        $response = $controller->flushFailed(new Request(['confirm' => 'true']));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['data' => ['flushed' => 3]], json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR));
        self::assertCount(2, $receiver->rejected);
        self::assertSame([], $receiver->pending);
    }
}
