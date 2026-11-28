<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Catalog\Infrastructure\Doctrine\Entity\MovieEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\MovieVideoEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\VideoEntity;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Library\Infrastructure\Doctrine\Entity\UserLibraryAccessEntity;
use App\Session\Application\Port\PlaybackOperation;
use App\Session\Infrastructure\Doctrine\Repository\ListeningSessionDoctrineRepository;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Session\PlaybackPersistenceScenarios;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class PlaybackViewingContextPersistenceTest extends TestCase
{
    use PlaybackPersistenceScenarios;

    public function testOpenedTitleFollowsTheExactVideoThroughColdReadsTransfersAndReloads(): void
    {
        $owner = $this->createUser();
        $media = $this->media($owner);
        $service = $this->coordinator();
        $loaded = $service->execute($owner, PlaybackOperation::Control, [
            'requestId' => Uuid::v7()->toString(), 'expectedRevision' => 0, 'action' => 'load',
            'media' => $media, 'position' => 12.5, 'desiredState' => 'paused',
        ]);
        self::assertSame($media, $loaded['media']);
        $this->manager->clear();
        self::assertSame($media, new ListeningSessionDoctrineRepository($this->manager)->findByUserId($owner)?->media()?->toArray());
        self::assertSame($media, $this->coordinator()->execute($owner, PlaybackOperation::Snapshot, [])['media']);
        $source = $this->endpoint($service, $owner);
        $snapshot = $service->execute($owner, PlaybackOperation::Transfer, [
            'requestId' => Uuid::v7()->toString(), 'expectedRevision' => $loaded['revision'],
            'targetIncarnationId' => $source['incarnationId'],
        ]);
        self::assertEquals($media, $this->pending($service, $owner, $source)['commands'][0]['payload']['media']);
        $this->answer($service, $owner, $source, 'prepared');
        $started = $this->answer($service, $owner, $source, 'started');

        $registration = $this->tabOnAPage($owner);
        $target = $service->execute($owner, PlaybackOperation::Endpoint, $registration)['endpoint'];
        $service->execute($owner, PlaybackOperation::Transfer, [
            'requestId' => Uuid::v7()->toString(), 'expectedRevision' => $started['revision'],
            'targetIncarnationId' => $target['incarnationId'],
        ]);
        $this->answer($service, $owner, $target, 'prepared');
        $this->answer($service, $owner, $source, 'quiesced', 45.0);
        self::assertEquals($media, $this->pending($service, $owner, $target)['commands'][0]['payload']['media']);
        $committed = $this->answer($service, $owner, $target, 'started');
        self::assertSame($media, $committed['media']);
        self::assertSame(45.0, $committed['position']);
        self::assertSame($media, $this->reload($service, $owner, $registration)['media']);
    }

    public function testContextlessLoadAndStaleLoadReplayCannotRestoreAnEarlierOpenedTitle(): void
    {
        $owner = $this->createUser();
        $media = $this->media($owner);
        $service = $this->coordinator();
        $original = [
            'requestId' => Uuid::v7()->toString(), 'expectedRevision' => 0, 'action' => 'load',
            'media' => $media, 'position' => 12.5, 'desiredState' => 'paused',
        ];
        $loaded = $service->execute($owner, PlaybackOperation::Control, $original);
        $legacy = ['kind' => 'video', 'publicId' => $media['publicId']];
        $cleared = $service->execute($owner, PlaybackOperation::Control, [
            'requestId' => Uuid::v7()->toString(), 'expectedRevision' => $loaded['revision'],
            'action' => 'load', 'media' => $legacy,
        ]);
        self::assertSame($legacy, $cleared['media']);
        self::assertSame(12.5, $cleared['position']);
        self::assertSame($legacy, $service->execute($owner, PlaybackOperation::Control, $original)['media']);
        try {
            $service->execute($owner, PlaybackOperation::Control, ['requestId' => Uuid::v7()->toString()] + $original);
            self::fail('A stale load cannot restore a previous opened title.');
        } catch (ConflictException) {
            self::assertSame($legacy, $service->execute($owner, PlaybackOperation::Snapshot, [])['media']);
        }
    }

    /** @return array{kind:string,publicId:string,viewing:array{titleKind:string,titleId:string}} */
    private function media(Uuid $owner): array
    {
        $library = new LibraryEntity('Movies', 'movies', '/playback/video', 'video', 'local');
        $movie = new MovieEntity(new PublicId(), $library, 'Movie');
        $video = new VideoEntity(new PublicId(), '/playback/video/movie.mkv', 'fixture-hash');
        foreach ([$library, $movie, $video, new MovieVideoEntity($movie, $video), new UserLibraryAccessEntity($owner, $library, new DateTimeImmutable())] as $entity) {
            $this->manager->persist($entity);
        }
        $this->manager->flush();

        return [
            'kind' => 'video', 'publicId' => $video->getPublicId()->toString(),
            'viewing' => ['titleKind' => 'movie', 'titleId' => $movie->getPublicId()->toString()],
        ];
    }
}
