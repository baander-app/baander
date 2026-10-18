<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Messaging;

use Symfony\Component\Messenger\Bridge\Redis\Transport\RedisTransport;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\CloseableTransportInterface;
use Symfony\Component\Messenger\Transport\Receiver\KeepaliveReceiverInterface;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\SetupableTransportInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/** Observes admission ownership around actual Redis transport operations. */
final readonly class AdmissionObservedTransport implements TransportInterface, KeepaliveReceiverInterface, SetupableTransportInterface, CloseableTransportInterface, MessageCountAwareInterface
{
    /** @param \Closure(string, Envelope): void $observe */
    public function __construct(private RedisTransport $inner, private \Closure $observe)
    {
    }

    public function get(): iterable { return $this->inner->get(); }

    public function send(Envelope $envelope): Envelope
    {
        $sent = $this->inner->send($envelope);
        ($this->observe)('sent', $sent);
        return $sent;
    }

    public function ack(Envelope $envelope): void
    {
        ($this->observe)('before_ack', $envelope);
        $this->inner->ack($envelope);
        ($this->observe)('after_ack', $envelope);
    }

    public function reject(Envelope $envelope): void
    {
        ($this->observe)('before_reject', $envelope);
        $this->inner->reject($envelope);
        ($this->observe)('after_reject', $envelope);
    }

    public function keepalive(Envelope $envelope, ?int $seconds = null): void
    {
        $this->inner->keepalive($envelope, $seconds);
        ($this->observe)('keepalive', $envelope);
    }

    public function setup(): void { $this->inner->setup(); }
    public function close(): void { $this->inner->close(); }
    public function getMessageCount(): int { return $this->inner->getMessageCount(); }
}
