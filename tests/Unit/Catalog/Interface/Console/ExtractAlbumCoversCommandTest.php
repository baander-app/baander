<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Interface\Console;

use App\Catalog\Domain\Repository\AlbumRepositoryInterface;
use App\Catalog\Interface\Console\ExtractAlbumCoversCommand;
use App\Metadata\Application\Command\ExtractAlbumCoverCommand;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class ExtractAlbumCoversCommandTest extends TestCase
{
    #[DataProvider('coverlessSetChanges')]
    public function testEveryOriginalAlbumIsDispatchedOnceWhileTheCoverlessSetChanges(bool $shrinks): void
    {
        $ids = array_map(static fn (): Uuid => Uuid::v7(), range(1, 501));
        usort($ids, static fn (Uuid $left, Uuid $right): int => strcmp($left->toString(), $right->toString()));
        $remaining = array_combine(array_map(static fn (Uuid $id): string => $id->toString(), $ids), $ids);
        $repository = $this->createMock(AlbumRepositoryInterface::class);
        $repository->expects($this->never())->method('findCoverlessAlbumIds');
        $cursors = [];
        $repository->expects($this->exactly(3))->method('findCoverlessAlbumIdsAfter')->willReturnCallback(
            static function (?Uuid $after, int $limit) use (&$remaining, &$cursors): array {
                self::assertSame(500, $limit);
                $cursors[] = $after;
                $page = array_values(array_filter($remaining, static fn (Uuid $id): bool => $after === null || strcmp($id->toString(), $after->toString()) > 0));
                return array_slice($page, 0, $limit);
            },
        );
        $repository->method('countCoverlessAlbums')->willReturn(501);
        $accepted = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->exactly(501))->method('dispatch')->willReturnCallback(
            static function (object $message) use (&$accepted, &$remaining, $shrinks): Envelope {
                self::assertInstanceOf(ExtractAlbumCoverCommand::class, $message);
                $id = $message->getAlbumId()->toString();
                $accepted[] = $id;
                if ($shrinks) {
                    unset($remaining[$id]);
                }
                return new Envelope($message);
            },
        );
        $tester = new CommandTester(new ExtractAlbumCoversCommand($repository, $bus));
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Dispatched 501 cover extraction job(s).', $tester->getDisplay());
        self::assertSame(array_map(static fn (Uuid $id): string => $id->toString(), $ids), $accepted);
        self::assertSame([null, $ids[499], $ids[500]], $cursors);
    }

    /** @return iterable<string, array{bool}> */
    public static function coverlessSetChanges(): iterable
    {
        yield 'accepted jobs immediately acquire covers' => [true];
        yield 'albums permanently remain coverless' => [false];
    }
    public function testNoCoverlessAlbumsSkipsPaginationAndDispatch(): void
    {
        $repository = $this->createMock(AlbumRepositoryInterface::class);
        $repository->expects($this->once())->method('countCoverlessAlbums')->willReturn(0);
        $repository->expects($this->never())->method('findCoverlessAlbumIds');
        $repository->expects($this->never())->method('findCoverlessAlbumIdsAfter');
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');
        $tester = new CommandTester(new ExtractAlbumCoversCommand($repository, $bus));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Nothing to do.', $tester->getDisplay());
    }

    public function testDispatchFailureStopsTheWalkAndPreservesTheOriginalException(): void
    {
        $ids = [Uuid::v7(), Uuid::v7(), Uuid::v7()];
        $repository = $this->createMock(AlbumRepositoryInterface::class);
        $repository->expects($this->once())->method('countCoverlessAlbums')->willReturn(3);
        $repository->expects($this->never())->method('findCoverlessAlbumIds');
        $repository->expects($this->once())->method('findCoverlessAlbumIdsAfter')->with(null, 500)->willReturn($ids);
        $failure = new RuntimeException('Cover queue unavailable');
        $attempted = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->exactly(2))->method('dispatch')->willReturnCallback(
            static function (object $message) use (&$attempted, $ids, $failure): Envelope {
                self::assertInstanceOf(ExtractAlbumCoverCommand::class, $message);
                $attempted[] = $message->getAlbumId();
                if ($message->getAlbumId()->equals($ids[1])) {
                    throw $failure;
                }
                return new Envelope($message);
            },
        );
        $tester = new CommandTester(new ExtractAlbumCoversCommand($repository, $bus));

        try {
            $tester->execute([]);
        } catch (RuntimeException $actual) {
            self::assertSame($failure, $actual);
            self::assertSame([$ids[0], $ids[1]], $attempted);
            self::assertStringNotContainsString('cover extraction job(s).', $tester->getDisplay());
            return;
        }
        self::fail('A rejected cover dispatch must stop the console walk.');
    }

}
