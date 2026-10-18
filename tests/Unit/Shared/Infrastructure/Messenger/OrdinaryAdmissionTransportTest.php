<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Messenger;

use App\Shared\Application\DTO\WorkerAutoscalingProfile;
use App\Shared\Infrastructure\Messenger\OrdinaryAdmissionTransport;
use App\Shared\Infrastructure\Worker\Autoscaling\AdmissionLedger;
use App\Shared\Infrastructure\Worker\Autoscaling\WorkerAdmissionContext;
use App\Shared\Infrastructure\Worker\DeploymentLease;
use App\Shared\Infrastructure\Worker\WorkerLaunchIdentity;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\CloseableTransportInterface;
use Symfony\Component\Messenger\Transport\Receiver\KeepaliveReceiverInterface;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\SetupableTransportInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

final class OrdinaryAdmissionTransportTest extends TestCase
{
    private string $directory;
    private string $boot;
    private AdmissionLedger $ledger;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/baander-admission-' . bin2hex(random_bytes(8));
        $this->boot = str_repeat('a', 32);
        mkdir($this->directory, 0700);
        mkdir($this->directory . '/' . $this->boot, 0700);
        $this->ledger = new AdmissionLedger($this->directory, new DeploymentLease('worker.baander.app', $this->boot, 1), new WorkerAutoscalingProfile(maximumConcurrency: 4));
        $now = hrtime(true) / 1e9;
        $this->ledger->initialize($now);
        foreach (['control', 'execution'] as $unit) {
            $path = $this->directory . '/' . $this->boot . '/' . $unit . '.json';
            file_put_contents($path, json_encode(['namespace' => 'worker.baander.app', 'bootId' => $this->boot, 'unit' => $unit,
                'epoch' => 1, 'ready' => true, 'draining' => false, 'time' => $now], JSON_THROW_ON_ERROR));
            chmod($path, 0600);
        }
        $this->ledger->publishDecision(4, 'test-demand', [], $now);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/' . $this->boot . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory . '/' . $this->boot);
        rmdir($this->directory);
    }

    public function testDownscaleWaitsForOwnedDeliveriesBeforeAnotherReceive(): void
    {
        $transports = $inners = $envelopes = [];
        for ($slot = 0; $slot < 4; ++$slot) {
            $inners[$slot] = new AdmissionTestTransport();
            $transports[$slot] = $this->transport($slot, $inners[$slot]);
            $envelopes[$slot] = iterator_to_array($transports[$slot]->get())[0];
            $transports[$slot]->received($envelopes[$slot]);
            $transports[$slot]->handled($envelopes[$slot]);
        }
        $this->ledger->publishDecision(1, 'test-shrink', [], hrtime(true) / 1e9);
        $transports[0]->ack($envelopes[0]);
        self::assertSame([], iterator_to_array($transports[0]->get()));
        self::assertSame(1, $inners[0]->receives);
        for ($slot = 1; $slot < 4; ++$slot) {
            $transports[$slot]->ack($envelopes[$slot]);
        }
        self::assertCount(1, iterator_to_array($transports[0]->get()));
        self::assertSame(2, $inners[0]->receives);
    }

    public function testParkedAdmissionDoesNotBlockCompletionSendOrKeepalive(): void
    {
        $inner = new AdmissionTestTransport();
        $transport = $this->transport(0, $inner);
        $envelope = iterator_to_array($transport->get())[0];
        $transport->received($envelope);
        $this->ledger->publishDecision(0, 'test-pressure', [], hrtime(true) / 1e9);
        $transport->keepalive($envelope, 30);
        self::assertSame($envelope, $transport->send($envelope));
        $transport->handled($envelope);
        $transport->reject($envelope);
        self::assertSame(1, $inner->keepalives);
        self::assertSame(1, $inner->rejections);
        self::assertNull($this->ledger->snapshot(hrtime(true) / 1e9)['slots']['consumer-0']['requestId']);
    }

    public function testEmptyPollReleasesWithoutRecordingCompletedWork(): void
    {
        $inner = new AdmissionTestTransport();
        $inner->empty = true;
        self::assertSame([], iterator_to_array($this->transport(0, $inner)->get()));
        $snapshot = $this->ledger->snapshot(hrtime(true) / 1e9);
        self::assertSame(0, $snapshot['completed']);
        self::assertNull($snapshot['slots']['consumer-0']['requestId']);
    }

    public function testAmbiguousAcknowledgementRetainsPermitAndStopsNewAdmission(): void
    {
        $inner = new AdmissionTestTransport();
        $transport = $this->transport(0, $inner);
        $envelope = iterator_to_array($transport->get())[0];
        $transport->received($envelope);
        $transport->handled($envelope);
        $inner->failAck = true;
        try {
            $transport->ack($envelope);
            self::fail('Ambiguous acknowledgement must fail the worker.');
        } catch (\RuntimeException $error) {
            self::assertStringNotContainsString('private', $error->getMessage());
        }
        $snapshot = $this->ledger->snapshot(hrtime(true) / 1e9);
        self::assertTrue($snapshot['fatal']);
        self::assertSame(0, $snapshot['target']);
        self::assertNotNull($snapshot['slots']['consumer-0']['requestId']);
    }

    private function transport(int $slot, AdmissionTestTransport $inner): OrdinaryAdmissionTransport
    {
        return new OrdinaryAdmissionTransport($inner, new WorkerAdmissionContext($this->ledger,
            new WorkerLaunchIdentity('worker.baander.app', $this->boot, 'consumer-' . $slot, 1)));
    }
}

final class AdmissionTestTransport implements TransportInterface, KeepaliveReceiverInterface, SetupableTransportInterface, CloseableTransportInterface, MessageCountAwareInterface
{
    public int $receives = 0;
    public int $keepalives = 0;
    public int $rejections = 0;
    public bool $empty = false;
    public bool $failAck = false;

    public function get(): iterable { ++$this->receives; return $this->empty ? [] : [new Envelope(new \stdClass())]; }
    public function ack(Envelope $envelope): void { if ($this->failAck) { throw new \RuntimeException('private transport details'); } }
    public function reject(Envelope $envelope): void { ++$this->rejections; }
    public function keepalive(Envelope $envelope, ?int $seconds = null): void { ++$this->keepalives; }
    public function send(Envelope $envelope): Envelope { return $envelope; }
    public function setup(): void { }
    public function close(): void { }
    public function getMessageCount(): int { return 1; }
}
