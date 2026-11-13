<?php

declare(strict_types=1);

namespace App\Tests\Unit\Session\Application;

use App\Media\Application\Port\PlaybackMediaAccessInterface;
use App\Session\Application\Port\PlaybackOperation;
use App\Session\Application\Port\PlaybackStoreInterface;
use App\Session\Application\Service\PlaybackCoordinator;
use App\Session\Domain\Model\ListeningSession\ListeningSession;
use App\Session\Domain\Model\Playback\PlaybackState;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/** A page that registers again is one live document: its new incarnation takes the earlier one's place. */
final class PlaybackSuccessionTest extends TestCase
{
    private Uuid $owner;
    private Uuid $runner;
    private string $destination;
    private string $page;
    /** @var array{destinationId:string,incarnationId:string,epoch:int,capability:string} */
    private array $registration;

    protected function setUp(): void
    {
        $this->owner = new Uuid();
        $this->runner = new Uuid();
        $this->destination = new PublicId()->toString();
        $this->page = new Uuid()->toString();
        $this->registration = ['destinationId' => $this->destination, 'incarnationId' => new Uuid()->toString(), 'epoch' => 4, 'capability' => 'capability'];
    }

    public function testTheSamePageSucceedsItsRunnerBeforeTheProofsRunAndReleasesNothing(): void
    {
        $session = $this->playingSession();
        $session->control('pause', null, null, null, null, null);
        $session->confirmControl(false, null);
        $selected = $session->selectedDestinationId();
        $revision = $session->revision();
        $order = [];
        $store = $this->store($session, null, [$this->runner->toString() => $this->page]);
        $store->expects(self::once())->method('succeedIncarnation')->with($this->runner->toString(), $this->registration['incarnationId'], 4)
            ->willReturnCallback(static function () use (&$order): void {
                $order[] = 'succeed';
            });
        // The proof is asked about the successor, which is registered, so it releases nothing.
        $store->expects(self::once())->method('retireConfirmedPausedBrowser')->with(self::callback(
            fn(ListeningSession $asked): bool => $asked->activeIncarnationId()?->toString() === $this->registration['incarnationId'],
        ))->willReturnCallback(static function () use (&$order): bool {
            $order[] = 'proof';
            return false;
        });
        $store->expects(self::once())->method('saveSession')->with($session);
        foreach (['supersedeIncarnationCommands', 'retireIncarnation', 'cancelCommands', 'saveTransfer', 'insertCommand'] as $method) {
            $store->expects(self::never())->method($method);
        }

        $result = $this->register($store, $this->page);

        self::assertSame(['succeed', 'proof'], $order);
        self::assertSame($this->registration, $result['endpoint']);
        self::assertSame($this->registration['incarnationId'], $session->activeIncarnationId()?->toString());
        self::assertSame($selected, $session->selectedDestinationId());
        self::assertSame(PlaybackState::Paused, $session->desiredState());
        self::assertSame('paused', $session->observedState()->value);
        self::assertSame(27.5, $session->getPosition());
        self::assertSame($revision, $session->revision());
    }

    public function testTheSamePageSucceedsItsPlaceInATransferWithoutBecomingTheRunner(): void
    {
        $session = $this->playingSession();
        $target = new Uuid();
        $revision = $session->revision();
        $transfer = ['id' => new Uuid()->toString(), 'state' => 'starting', 'source_incarnation_id' => $this->runner->toString(),
            'target_incarnation_id' => $target->toString(), 'expires_at' => new DateTimeImmutable('+20 seconds')->format(DATE_ATOM)];
        // The source belongs to another tab; the target is this page's earlier incarnation.
        $store = $this->store($session, $transfer, [$target->toString() => $this->page]);
        $store->expects(self::once())->method('succeedIncarnation')->with($target->toString(), $this->registration['incarnationId'], 4);
        foreach (['saveSession', 'supersedeIncarnationCommands', 'retireIncarnation', 'cancelCommands', 'saveTransfer'] as $method) {
            $store->expects(self::never())->method($method);
        }

        $this->register($store, $this->page);

        self::assertSame($this->runner, $session->activeIncarnationId());
        self::assertSame($revision, $session->revision());
    }

    public function testAnotherPageSucceedsNothing(): void
    {
        $session = $this->playingSession();
        $store = $this->store($session, null, [$this->runner->toString() => new Uuid()->toString()]);
        $store->expects(self::never())->method('succeedIncarnation');
        $store->expects(self::once())->method('supersedeIncarnationCommands')->with($this->runner->toString());

        $this->register($store, $this->page);

        self::assertNull($session->activeIncarnationId());
    }

    private function playingSession(): ListeningSession
    {
        $session = ListeningSession::create($this->owner, [], 0, 27.5);
        $session->commitDestination($this->runner, new Uuid(), PlaybackState::Playing, 27.5);
        return $session;
    }

    /**
     * @param array<string,mixed>|null $transfer
     * @param array<string,string> $pages The page each incarnation of the registering tab registered from.
     */
    private function store(ListeningSession $session, ?array $transfer, array $pages): MockObject&PlaybackStoreInterface
    {
        $store = $this->createMock(PlaybackStoreInterface::class);
        $store->method('lockSession')->willReturn($session);
        $store->method('currentTransfer')->willReturn($transfer);
        $store->method('pendingControl')->willReturn(null);
        $store->method('register')->willReturn($this->registration);
        $store->method('endpoint')->willReturnCallback(fn(string $owner, string $incarnationId): array => ['id' => $incarnationId,
            'public_id' => isset($pages[$incarnationId]) ? $this->destination : new PublicId()->toString(), 'page_id' => $pages[$incarnationId] ?? new Uuid()->toString()]);
        $store->method('snapshot')->willReturn([]);
        return $store;
    }

    /** @return array<string,mixed> */
    private function register(PlaybackStoreInterface $store, string $pageId): array
    {
        $transactions = $this->createStub(TransactionPortInterface::class);
        $transactions->method('run')->willReturnCallback(static fn(callable $operation): mixed => $operation());
        return new PlaybackCoordinator($store, $transactions, $this->createStub(PlaybackMediaAccessInterface::class))
            ->execute($this->owner, PlaybackOperation::Endpoint, ['deviceId' => new Uuid()->toString(), 'endpointId' => new Uuid()->toString(), 'pageId' => $pageId]);
    }
}
