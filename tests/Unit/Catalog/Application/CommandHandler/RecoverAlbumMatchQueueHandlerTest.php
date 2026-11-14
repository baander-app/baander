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

    private InMemoryAlbumMatchStates $states;
    private AlbumMatchQueueChain $chains;
    /** @var list<object> */
    private array $dispatched = [];

    protected function setUp(): void
    {
        $this->states = new InMemoryAlbumMatchStates();
        $this->chains = new AlbumMatchQueueChain(new ArrayAdapter());
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

    private function recover(): string
    {
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $message): Envelope {
            $this->dispatched[] = $message;

            return new Envelope($message);
        });

        return new RecoverAlbumMatchQueueHandler($this->states, $this->chains, $bus, new MockClock(self::NOW), new NullLogger())(
            new RecoverAlbumMatchQueueCommand(),
        );
    }

    private function request(string $due): AlbumMatchState
    {
        return new AlbumMatchState(Uuid::v7(), AlbumMatchStatus::Requested, AlbumMatchSource::Ingest, new DateTimeImmutable(self::NOW . ' ' . $due));
    }
}
