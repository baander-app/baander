<?php

declare(strict_types=1);

namespace App\Tests\Unit\Session\Application;

use App\Media\Application\Port\PlaybackMediaAccessInterface;
use App\Session\Application\Port\PlaybackOperation;
use App\Session\Application\Port\PlaybackStoreInterface;
use App\Session\Application\Service\PlaybackCoordinator;
use App\Session\Domain\Model\ListeningSession\ListeningSession;
use App\Session\Domain\Model\Playback\PlaybackMedia;
use App\Session\Domain\Model\Playback\PlaybackState;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConfirmedPausedRunnerRecoveryTest extends TestCase
{
    public function testSnapshotReleasesConfirmedPausedRunnerAndRetainsMediaQueueAndCursor(): void
    {
        $owner = new Uuid();
        $runner = new Uuid();
        $track = new PublicId();
        $session = ListeningSession::create($owner, [$track->toString()], 0, 27.5);
        $session->control('load', new PlaybackMedia('song', $track), null, null, null, PlaybackState::Paused);
        $session->commitDestination($runner, new Uuid(), PlaybackState::Paused, 27.5);
        $revision = $session->revision();
        $store = $this->createMock(PlaybackStoreInterface::class);
        $store->method('lockSession')->willReturn($session);
        $store->expects(self::once())->method('retireConfirmedPausedBrowser')->with($session)->willReturn(true);
        $store->expects(self::once())->method('saveSession')->with($session);
        $store->method('snapshot')->willReturn([]);
        $transactions = $this->createStub(TransactionPortInterface::class);
        $transactions->method('run')->willReturnCallback(static fn(callable $operation): mixed => $operation());

        (new PlaybackCoordinator($store, $transactions, $this->createStub(PlaybackMediaAccessInterface::class)))
            ->execute($owner, PlaybackOperation::Snapshot, []);

        self::assertNull($session->activeIncarnationId());
        self::assertNull($session->selectedDestinationId());
        self::assertSame($revision + 1, $session->revision());
        self::assertSame([$track->toString()], $session->getQueue());
        self::assertSame($track->toString(), $session->media()?->publicId->toString());
        self::assertSame(27.5, $session->getPosition());
        self::assertSame(PlaybackState::Paused, $session->desiredState());
        self::assertSame('paused', $session->observedState()->value);
    }

    public function testMissingDurableProofKeepsUnavailableRunnerAuthority(): void
    {
        $owner = new Uuid();
        $runner = new Uuid();
        $session = ListeningSession::create($owner, [], 0, 9);
        $session->commitDestination($runner, new Uuid(), PlaybackState::Paused, 9);
        $store = $this->createMock(PlaybackStoreInterface::class);
        $store->method('lockSession')->willReturn($session);
        $store->expects(self::once())->method('retireConfirmedPausedBrowser')->willReturn(false);
        $store->expects(self::never())->method('saveSession');
        $store->method('snapshot')->willReturn([]);
        $transactions = $this->createStub(TransactionPortInterface::class);
        $transactions->method('run')->willReturnCallback(static fn(callable $operation): mixed => $operation());

        (new PlaybackCoordinator($store, $transactions, $this->createStub(PlaybackMediaAccessInterface::class)))
            ->execute($owner, PlaybackOperation::Snapshot, []);

        self::assertSame($runner, $session->activeIncarnationId());
        self::assertSame(0, $session->revision());
        self::assertSame(9.0, $session->getPosition());
    }

    public function testEndpointRegistrationReleasesAConfirmedPausedRunnerInTheSameOperation(): void
    {
        $owner = new Uuid();
        $session = $this->pausedSession($owner, new Uuid());
        $revision = $session->revision();
        $order = [];
        $store = $this->createMock(PlaybackStoreInterface::class);
        $store->method('lockSession')->willReturn($session);
        $store->expects(self::once())->method('register')->willReturnCallback(static function () use (&$order): array {
            $order[] = 'register';
            return ['incarnationId' => new Uuid()->toString()];
        });
        $store->expects(self::once())->method('retireConfirmedPausedBrowser')->with($session)->willReturnCallback(static function () use (&$order): bool {
            $order[] = 'retire';
            return true;
        });
        $store->expects(self::never())->method('retireConfirmedStoppedBrowser');
        $store->expects(self::once())->method('saveSession')->with($session)->willReturnCallback(static function () use (&$order): void {
            $order[] = 'save';
        });
        $store->method('snapshot')->willReturn([]);

        $result = $this->register($store, $owner);

        self::assertSame(['register', 'retire', 'save'], $order);
        self::assertArrayHasKey('endpoint', $result);
        self::assertNull($session->activeIncarnationId());
        self::assertNull($session->selectedDestinationId());
        self::assertSame($revision + 1, $session->revision());
        self::assertSame(PlaybackState::Paused, $session->desiredState());
        self::assertSame('paused', $session->observedState()->value);
        self::assertSame(27.5, $session->getPosition());
    }

    public function testEndpointRegistrationKeepsARunnerWithoutProof(): void
    {
        $owner = new Uuid();
        $runner = new Uuid();
        $session = $this->pausedSession($owner, $runner);
        $revision = $session->revision();
        $store = $this->createMock(PlaybackStoreInterface::class);
        $store->method('lockSession')->willReturn($session);
        $store->method('register')->willReturn(['incarnationId' => new Uuid()->toString()]);
        $store->expects(self::once())->method('retireConfirmedPausedBrowser')->with($session)->willReturn(false);
        $store->expects(self::never())->method('saveSession');
        $store->method('snapshot')->willReturn([]);

        $this->register($store, $owner);

        self::assertSame($runner, $session->activeIncarnationId());
        self::assertSame($revision, $session->revision());
    }

    public function testEndpointRegistrationDoesNotReleaseDuringAnUnfinishedTransfer(): void
    {
        $owner = new Uuid();
        $runner = new Uuid();
        $session = $this->pausedSession($owner, $runner);
        $store = $this->createMock(PlaybackStoreInterface::class);
        $store->method('lockSession')->willReturn($session);
        $store->method('currentTransfer')->willReturn([
            'id' => new Uuid()->toString(), 'state' => 'starting',
            'expires_at' => new \DateTimeImmutable('+20 seconds')->format(DATE_ATOM),
        ]);
        $store->method('register')->willReturn(['incarnationId' => new Uuid()->toString()]);
        $store->expects(self::never())->method('retireConfirmedPausedBrowser');
        $store->expects(self::never())->method('saveSession');
        $store->method('snapshot')->willReturn([]);

        $this->register($store, $owner);

        self::assertSame($runner, $session->activeIncarnationId());
    }

    public function testARegistrationFromAnotherPageReleasesARunnerNoProofReleases(): void
    {
        $owner = new Uuid();
        $runner = new Uuid();
        $session = $this->pausedSession($owner, $runner);
        $session->control('play', null, null, null, null, null);
        $session->confirmControl(false, 61.0);
        $revision = $session->revision();
        $destination = new PublicId()->toString();
        $store = $this->createMock(PlaybackStoreInterface::class);
        $store->method('lockSession')->willReturn($session);
        $store->method('register')->willReturn(['destinationId' => $destination, 'incarnationId' => new Uuid()->toString()]);
        $store->method('endpoint')->willReturn(['id' => $runner->toString(), 'public_id' => $destination, 'page_id' => new Uuid()->toString()]);
        $store->expects(self::never())->method('retireConfirmedPausedBrowser');
        $store->expects(self::once())->method('supersedeIncarnationCommands')->with($runner->toString());
        $store->expects(self::once())->method('retireIncarnation')->with($runner->toString(), true);
        $store->expects(self::once())->method('saveSession')->with($session);
        $store->method('snapshot')->willReturn([]);

        $this->register($store, $owner, new Uuid()->toString());

        self::assertNull($session->activeIncarnationId());
        self::assertNull($session->selectedDestinationId());
        self::assertSame(PlaybackState::Paused, $session->desiredState());
        self::assertSame('paused', $session->observedState()->value);
        self::assertSame(61.0, $session->getPosition());
        self::assertSame($revision + 1, $session->revision());
    }

    /** @return iterable<string,array{string,array<string,mixed>,string}> The registration's page, the runner's row and the registered destination. */
    public static function registrationsThatProveNothing(): iterable
    {
        $destination = new PublicId()->toString();
        $page = new Uuid()->toString();
        yield 'the runner belongs to another tab' => [$page, ['public_id' => new PublicId()->toString(), 'page_id' => new Uuid()->toString()], $destination];
        yield 'the runner registered without a page identity' => [$page, ['public_id' => $destination, 'page_id' => null], $destination];
    }

    /** @param array<string,mixed> $row */
    #[DataProvider('registrationsThatProveNothing')]
    public function testARegistrationThatProvesNothingAboutTheRunnerKeepsIt(string $page, array $row, string $destination): void
    {
        $owner = new Uuid();
        $runner = new Uuid();
        $session = $this->pausedSession($owner, $runner);
        $session->control('play', null, null, null, null, null);
        $session->confirmControl(false, null);
        $revision = $session->revision();
        $store = $this->createMock(PlaybackStoreInterface::class);
        $store->method('lockSession')->willReturn($session);
        $store->method('register')->willReturn(['destinationId' => $destination, 'incarnationId' => new Uuid()->toString()]);
        $store->method('endpoint')->willReturn(['id' => $runner->toString()] + $row);
        foreach (['supersedeIncarnationCommands', 'retireIncarnation', 'saveSession'] as $method) {
            $store->expects(self::never())->method($method);
        }
        $store->method('snapshot')->willReturn([]);

        $this->register($store, $owner, $page);

        self::assertSame($runner, $session->activeIncarnationId());
        self::assertSame($revision, $session->revision());
        self::assertSame('playing', $session->observedState()->value);
    }

    public function testARegistrationWithoutAPageIdentityReadsNoEarlierIncarnation(): void
    {
        $owner = new Uuid();
        $runner = new Uuid();
        $session = $this->pausedSession($owner, $runner);
        $session->control('play', null, null, null, null, null);
        $session->confirmControl(false, null);
        $store = $this->createMock(PlaybackStoreInterface::class);
        $store->method('lockSession')->willReturn($session);
        $store->expects(self::once())->method('register')->with($owner->toString(), self::callback(static fn(array $input): bool => $input['pageId'] === null))
            ->willReturn(['incarnationId' => new Uuid()->toString()]);
        foreach (['endpoint', 'supersedeIncarnationCommands', 'retireIncarnation', 'saveSession'] as $method) {
            $store->expects(self::never())->method($method);
        }
        $store->method('snapshot')->willReturn([]);

        $this->register($store, $owner);

        self::assertSame($runner, $session->activeIncarnationId());
    }

    public function testAPageIdentityThatIsNoUuidIsRefusedBeforeAnythingIsRegistered(): void
    {
        $owner = new Uuid();
        $session = $this->pausedSession($owner, new Uuid());
        $store = $this->createMock(PlaybackStoreInterface::class);
        $store->method('lockSession')->willReturn($session);
        $store->expects(self::never())->method('register');

        foreach (['this page', 17, ['page']] as $page) {
            try {
                new PlaybackCoordinator($store, $this->transactions(), $this->createStub(PlaybackMediaAccessInterface::class))
                    ->execute($owner, PlaybackOperation::Endpoint, ['deviceId' => new Uuid()->toString(), 'endpointId' => new Uuid()->toString(), 'pageId' => $page]);
                self::fail('A page identity that is no UUID was accepted.');
            } catch (InvalidInputException $error) {
                self::assertSame('pageId must be a UUID.', $error->getMessage());
            }
        }
    }

    private function pausedSession(Uuid $owner, Uuid $runner): ListeningSession
    {
        $track = new PublicId();
        $session = ListeningSession::create($owner, [$track->toString()], 0, 27.5);
        $session->control('load', new PlaybackMedia('song', $track), null, null, null, PlaybackState::Paused);
        $session->commitDestination($runner, new Uuid(), PlaybackState::Paused, 27.5);
        return $session;
    }

    /** @return array<string,mixed> */
    private function register(PlaybackStoreInterface $store, Uuid $owner, ?string $pageId = null): array
    {
        return new PlaybackCoordinator($store, $this->transactions(), $this->createStub(PlaybackMediaAccessInterface::class))
            ->execute($owner, PlaybackOperation::Endpoint, ['deviceId' => new Uuid()->toString(), 'endpointId' => new Uuid()->toString()]
                + ($pageId === null ? [] : ['pageId' => $pageId]));
    }

    private function transactions(): TransactionPortInterface
    {
        $transactions = $this->createStub(TransactionPortInterface::class);
        $transactions->method('run')->willReturnCallback(static fn(callable $operation): mixed => $operation());
        return $transactions;
    }
}
