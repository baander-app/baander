<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application\CommandHandler;

use App\Catalog\Application\Command\RecoverAlbumMatchQueueCommand;
use App\Catalog\Application\CommandHandler\RecoverAlbumMatchQueueHandler;
use App\Catalog\Application\Message\DrainAlbumMatchesMessage;
use App\Catalog\Application\Service\MusicBrainzMatch\AlbumMatchQueueChain;
use App\Catalog\Domain\ValueObject\AlbumMatchSource;
use App\Catalog\Domain\ValueObject\AlbumMatchState;
use App\Catalog\Domain\ValueObject\AlbumMatchStatus;
use App\Shared\Domain\Model\Uuid;
use App\Metadata\Application\Port\MusicBrainzLookupInterface;
use App\Tests\Fixtures\Catalog\InMemoryAlbumMatchStates;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The scheduled recovery of the album match queue starts a new chain of queue runs when a request
 * is due and no chain holds the queue, as after a lost queue message, and leaves a working queue
 * alone.
 */
final class RecoverAlbumMatchQueueHandlerTest extends TestCase
{
    private const string NOW = '2026-11-14 12:00:00.000000 UTC';

    private bool $musicBrainzEnabled = true;
    private MockClock $clock;
    private InMemoryAlbumMatchStates $states;
    private AlbumMatchQueueChain $chains;
    /** @var list<object> */
    private array $dispatched = [];

    protected function setUp(): void
    {
        $this->clock = new MockClock(self::NOW);
        $this->states = new InMemoryAlbumMatchStates($this->clock);
        $this->chains = new AlbumMatchQueueChain(new ArrayAdapter());
    }

    public function testDisabledMusicBrainzLeavesDueRequestsWaitingUntilItIsEnabled(): void
    {
        $state = $this->request('-30 minutes');
        $this->states->has($state);
        $this->musicBrainzEnabled = false;

        self::assertSame('provider disabled', $this->recover());
        self::assertSame([], $this->dispatched);
        self::assertSame($state->dueAt, $this->states->nextRequestDueAt());

        $this->musicBrainzEnabled = true;
        self::assertSame('queue started', $this->recover());
        self::assertEquals([new DrainAlbumMatchesMessage()], $this->dispatched);
    }

    public function testADueRequestThatNoChainHoldsStartsTheQueueAgain(): void
    {
        $this->states->has($this->request('-30 minutes'));

        self::assertSame('queue started', $this->recover());
        self::assertEquals([new DrainAlbumMatchesMessage()], $this->dispatched, 'A run without a chain starts one in place of any other.');
    }

    public function testAQueueAChainHoldsIsLeftAlone(): void
    {
        $this->states->has($this->request('-30 minutes'));
        $this->chains->start(60);

        self::assertSame('queue running', $this->recover());
        self::assertSame([], $this->dispatched);
    }

    public function testNothingStartsWhileNoRequestIsDue(): void
    {
        self::assertSame('no match due', $this->recover());

        $this->states->has($this->request('+4 minutes'));
        self::assertSame('no match due', $this->recover(), 'A request still settling is started by its own run.');
        self::assertSame([], $this->dispatched);
    }

    public function testARequestARunHoldsFallsDueOnlyOnceItsClaimHasLapsed(): void
    {
        $this->states->has($this->request('-30 minutes'));
        $this->states->claimOldestDueRequest($this->clock->now(), Uuid::v7(), 900);

        self::assertSame('no match due', $this->recover(), 'The run that holds the only due request is matching it.');

        $this->clock->sleep(900);

        self::assertSame('queue started', $this->recover(), 'The run died: its claim has lapsed and no chain holds the queue.');
    }

    private function recover(): string
    {
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $message): Envelope {
            $this->dispatched[] = $message;

            return new Envelope($message);
        });

        return new RecoverAlbumMatchQueueHandler($this->states, $this->chains, $bus, $this->clock, new NullLogger(), $this->lookup())(
            new RecoverAlbumMatchQueueCommand(),
        );
    }

    private function lookup(): MusicBrainzLookupInterface
    {
        $lookup = $this->createStub(MusicBrainzLookupInterface::class);
        $lookup->method('isEnabled')->willReturnCallback(fn (): bool => $this->musicBrainzEnabled);

        return $lookup;
    }

    private function request(string $due): AlbumMatchState
    {
        return new AlbumMatchState(Uuid::v7(), AlbumMatchStatus::Requested, AlbumMatchSource::Ingest, new DateTimeImmutable(self::NOW . ' ' . $due));
    }
}
