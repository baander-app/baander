<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Infrastructure\Auth;

use App\Auth\Infrastructure\Security\SecurityUser;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\Command\CreateTranscodeSessionCommand;
use App\Transcode\Infrastructure\Auth\AuthorizedPlayback;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class AuthorizedPlaybackTest extends TestCase
{
    #[DataProvider('actors')]
    public function testPlaybackChecksLibraryMembership(string $actor, bool $exists, bool $allowed): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE videos (id TEXT PRIMARY KEY)');
        $connection->executeStatement('CREATE TABLE movies (id TEXT PRIMARY KEY, library_id TEXT)');
        $connection->executeStatement('CREATE TABLE movie_video (video_id TEXT, movie_id TEXT)');
        $connection->executeStatement('CREATE TABLE user_library_access (user_id TEXT, library_id TEXT)');
        $userId = new Uuid();
        $videoId = new Uuid();
        if ($exists) {
            $connection->insert('videos', ['id' => $videoId->toString()]);
            $connection->insert('movies', ['id' => 'movie', 'library_id' => 'library']);
            $connection->insert('movie_video', ['video_id' => $videoId->toString(), 'movie_id' => 'movie']);
        }
        $connection->insert('user_library_access', ['user_id' => $userId->toString(), 'library_id' => $actor === 'member' ? 'library' : 'another-library']);
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($actor === 'anonymous' ? null : new SecurityUser($userId->toString(), 'test@example.test', ''));
        $security->method('isGranted')->willReturn($actor === 'admin');
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($allowed ? $this->exactly(3) : $this->never())->method('dispatch')
            ->willReturnCallback(static function (CreateTranscodeSessionCommand $command) use ($userId, $videoId): Envelope {
                self::assertTrue($command->getUserId()->equals($userId));
                self::assertTrue($command->getVideoId()->equals($videoId));
                return new Envelope($command);
            });
        $playback = new AuthorizedPlayback($security, $connection, $bus);
        if (!$allowed) {
            $this->expectException(AccessDeniedException::class);
        }
        try {
            $playback->start($videoId);
        } finally {
            $connection->close();
        }
    }

    /** @return iterable<array{string, bool, bool}> */
    public static function actors(): iterable
    {
        yield ['member', true, true];
        yield ['unrelated', true, false];
        yield ['admin', true, true];
        yield ['anonymous', true, false];
        yield ['admin', false, false];
        yield ['member', false, false];
    }

    public function testFindsTheVideoATranscodeJobEncodes(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE transcode_jobs (id TEXT PRIMARY KEY, video_id TEXT NOT NULL)');
        $jobId = new Uuid();
        $videoId = new Uuid();
        $connection->insert('transcode_jobs', ['id' => $jobId->toString(), 'video_id' => $videoId->toString()]);
        $playback = new AuthorizedPlayback($this->createStub(Security::class), $connection, $this->createStub(MessageBusInterface::class));

        try {
            self::assertTrue($playback->findTranscodeJobVideoId($jobId)?->equals($videoId));
            self::assertNull($playback->findTranscodeJobVideoId(new Uuid()));
        } finally {
            $connection->close();
        }
    }
}
