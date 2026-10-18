<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger;

use App\Shared\Infrastructure\Worker\Autoscaling\WorkerAdmissionContext;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\Messenger\Bridge\Redis\Transport\RedisTransport;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\CloseableTransportInterface;
use Symfony\Component\Messenger\Transport\Receiver\KeepaliveReceiverInterface;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\SetupableTransportInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/** Receive admission never gates publication, keepalive or owned completion. */
#[Exclude]
final class OrdinaryAdmissionTransport implements TransportInterface, KeepaliveReceiverInterface, SetupableTransportInterface, CloseableTransportInterface, MessageCountAwareInterface
{
    private int $sequence = 0;
    private ?string $activeRequest = null;

    public function __construct(
        private readonly TransportInterface&KeepaliveReceiverInterface&SetupableTransportInterface&CloseableTransportInterface&MessageCountAwareInterface $inner,
        private readonly WorkerAdmissionContext $admission,
    ) {
    }

    public static function forEnvironment(TransportInterface $inner): TransportInterface
    {
        $admission = WorkerAdmissionContext::fromEnvironment();
        if ($admission === null) {
            return $inner;
        }
        // The admitted Redis receiver returns at most one envelope per get().
        if (!$inner instanceof RedisTransport) {
            throw new \RuntimeException('Ordinary admission requires the configured single-delivery Redis transport.');
        }
        return new self($inner, $admission);
    }

    public function get(): iterable
    {
        if ($this->activeRequest !== null) {
            $this->fail($this->activeRequest, 'unfinished_delivery');
        }
        $request = bin2hex(random_bytes(16));
        if (!$this->admission->ledger->acquire($this->admission->identity, ++$this->sequence, $request, hrtime(true) / 1e9)) {
            return [];
        }
        $this->activeRequest = $request;
        try {
            $result = [];
            foreach ($this->inner->get() as $envelope) {
                if ($result !== []) {
                    throw new \RuntimeException('Unsupported ordinary receiver batching.');
                }
                $result[] = $envelope->with(new OrdinaryAdmissionStamp($request));
            }
            if ($result === []) {
                $this->admission->ledger->complete($this->admission->identity, $request, hrtime(true) / 1e9);
                $this->activeRequest = null;
            }
            return $result;
        } catch (\Throwable) {
            $this->fail($request, 'receive_uncertain');
        }
    }

    public function received(Envelope $envelope): void
    {
        $this->admission->ledger->received($this->admission->identity, $this->request($envelope), hrtime(true) / 1e9);
    }

    public function handled(Envelope $envelope): void
    {
        $this->admission->ledger->handled($this->admission->identity, $this->request($envelope), hrtime(true) / 1e9);
    }

    public function ack(Envelope $envelope): void
    {
        $this->finish($envelope, false);
    }

    public function reject(Envelope $envelope): void
    {
        $this->finish($envelope, true);
    }

    public function keepalive(Envelope $envelope, ?int $seconds = null): void
    {
        $this->inner->keepalive($envelope, $seconds);
    }

    public function send(Envelope $envelope): Envelope
    {
        return $this->inner->send($envelope);
    }

    public function setup(): void { $this->inner->setup(); }
    public function close(): void { $this->inner->close(); }
    public function getMessageCount(): int { return $this->inner->getMessageCount(); }

    private function finish(Envelope $envelope, bool $reject): void
    {
        $request = $this->request($envelope);
        try {
            if ($reject) {
                $this->inner->reject($envelope);
            } else {
                $this->inner->ack($envelope);
            }
            $this->admission->ledger->complete($this->admission->identity, $request, hrtime(true) / 1e9);
            $this->activeRequest = null;
        } catch (\Throwable) {
            $this->fail($request, 'completion_uncertain');
        }
    }

    private function request(Envelope $envelope): string
    {
        $request = $envelope->last(OrdinaryAdmissionStamp::class)?->requestId;
        if ($request === null || $request !== $this->activeRequest) {
            throw new \RuntimeException('Ordinary delivery does not own the active admission permit.');
        }
        return $request;
    }

    private function fail(string $request, string $code): never
    {
        try {
            $this->admission->ledger->fault($this->admission->identity, $request, $code, hrtime(true) / 1e9);
        } catch (\Throwable) {
            // An unavailable ledger cannot release possibly owned work.
        }
        throw new \RuntimeException('Ordinary delivery ownership is uncertain; worker reconciliation is required.');
    }
}
