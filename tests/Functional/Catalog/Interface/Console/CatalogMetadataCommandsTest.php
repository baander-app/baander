<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalog\Interface\Console;

use App\Catalog\Infrastructure\Doctrine\Entity\AlbumEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\ArtistEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\MovieEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\SongEntity;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Shared\Domain\Model\PublicId;
use App\Tests\Functional\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The metadata update routes and app:{album,song,movie,artist}:update, and artist creation and
 * app:artist:create, reach the same use cases and report the same outcomes and data.
 */
final class CatalogMetadataCommandsTest extends TestCase
{
    public function testAnAlbumUpdateStoresTheSameOnBothPathsAndPrintsTheApiData(): void
    {
        $admin = $this->createAdminUser();
        $album = $this->album();

        $api = $this->assertJsonResponse(
            $this->authenticatedRequest('PATCH', '/api/albums/' . $album, $admin, ['title' => 'Abbey Road', 'year' => 1969]),
            200,
            'data',
        )['data'];
        self::assertSame(['Abbey Road', 1969], [$this->albumEntity($album)->getTitle(), $this->albumEntity($album)->getYear()]);

        $update = $this->command('app:album:update');
        self::assertSame(Command::SUCCESS, $update->execute([
            'public-id' => $album,
            '--title' => 'Abbey Road',
            '--year' => '1969',
            '--json' => true,
        ]), $update->getDisplay());

        self::assertSame($api, $this->decode($update));
        self::assertSame(['Abbey Road', 1969], [$this->albumEntity($album)->getTitle(), $this->albumEntity($album)->getYear()]);
    }

    public function testAnUnknownLockedFieldIsInvalidOnBothPathsAndChangesNothing(): void
    {
        $admin = $this->createAdminUser();
        $album = $this->album();

        $error = $this->assertJsonResponse(
            $this->authenticatedRequest('PATCH', '/api/albums/' . $album, $admin, ['title' => 'Changed', 'lockedFields' => ['colour']]),
            422,
        );
        self::assertSame('Cannot lock unknown field "colour".', $error['error']['message']);

        $update = $this->command('app:album:update');
        self::assertSame(Command::INVALID, $update->execute(['public-id' => $album, '--title' => 'Changed', '--lock' => ['colour']]));
        self::assertStringContainsString('Cannot lock unknown field "colour".', $update->getDisplay());

        $stored = $this->albumEntity($album);
        self::assertSame('Fixture album', $stored->getTitle());
        self::assertSame([], $stored->getLockedFields());
    }

    public function testOneRequestMayUnlockATitleAndChangeIt(): void
    {
        $admin = $this->createAdminUser();
        $album = $this->album(['title']);

        $this->assertJsonResponse(
            $this->authenticatedRequest('PATCH', '/api/albums/' . $album, $admin, ['title' => 'Let It Be', 'lockedFields' => []]),
            200,
        );
        self::assertSame('Let It Be', $this->albumEntity($album)->getTitle());
        self::assertSame([], $this->albumEntity($album)->getLockedFields());

        $this->lock($album, ['title']);
        $update = $this->command('app:album:update');
        self::assertSame(Command::SUCCESS, $update->execute(['public-id' => $album, '--unlock' => ['title'], '--title' => 'Help!']), $update->getDisplay());
        self::assertSame('Help!', $this->albumEntity($album)->getTitle());
        self::assertSame([], $this->albumEntity($album)->getLockedFields());
    }

    public function testOneRequestMayChangeATitleAndLockIt(): void
    {
        $admin = $this->createAdminUser();
        $album = $this->album();

        $this->assertJsonResponse(
            $this->authenticatedRequest('PATCH', '/api/albums/' . $album, $admin, ['title' => 'Let It Be', 'lockedFields' => ['title']]),
            200,
        );
        self::assertSame('Let It Be', $this->albumEntity($album)->getTitle());
        self::assertSame(['title'], $this->albumEntity($album)->getLockedFields());

        $this->lock($album, []);
        $update = $this->command('app:album:update');
        self::assertSame(Command::SUCCESS, $update->execute(['public-id' => $album, '--title' => 'Help!', '--lock' => ['title']]), $update->getDisplay());
        self::assertSame('Help!', $this->albumEntity($album)->getTitle());
        self::assertSame(['title'], $this->albumEntity($album)->getLockedFields());
    }

    public function testAChangeToALockedLabelIsRejectedOnBothPaths(): void
    {
        $admin = $this->createAdminUser();
        $album = $this->album(['label']);

        $error = $this->assertJsonResponse($this->authenticatedRequest('PATCH', '/api/albums/' . $album, $admin, ['label' => 'Apple']), 422);
        self::assertSame('Field "label" is locked and cannot be updated.', $error['error']['message']);

        $update = $this->command('app:album:update');
        self::assertSame(Command::INVALID, $update->execute(['public-id' => $album, '--label' => 'Apple']));
        self::assertStringContainsString('Field "label" is locked and cannot be updated.', $update->getDisplay());

        self::assertNull($this->albumEntity($album)->getLabel());
    }

    public function testAMalformedPublicIdIsInvalidAndAnUnknownOneIsNotFoundOnEveryUpdateRoute(): void
    {
        $admin = $this->createAdminUser();
        $unknown = (new PublicId())->toString();

        foreach (['albums' => 'app:album:update', 'songs' => 'app:song:update', 'movies' => 'app:movie:update', 'artists' => 'app:artist:update'] as $path => $name) {
            $this->assertJsonResponse($this->authenticatedRequest('PATCH', '/api/' . $path . '/not-valid', $admin, ['title' => 'X', 'name' => 'X']), 422);
            $this->assertJsonResponse($this->authenticatedRequest('PATCH', '/api/' . $path . '/' . $unknown, $admin, ['title' => 'X', 'name' => 'X']), 404);

            $malformed = $this->command($name);
            self::assertSame(Command::INVALID, $malformed->execute(['public-id' => 'not-valid']), $name);
            $missing = $this->command($name);
            self::assertSame(Command::FAILURE, $missing->execute(['public-id' => $unknown]), $name);
        }
    }

    public function testASongUpdatePrintsTheApiDataAndALockedTitleIsRejected(): void
    {
        $admin = $this->createAdminUser();
        $song = $this->song();

        $api = $this->assertJsonResponse(
            $this->authenticatedRequest('PATCH', '/api/songs/' . $song, $admin, ['track' => 2, 'explicit' => true, 'lockedFields' => ['title']]),
            200,
            'data',
        )['data'];
        self::assertSame(['title'], $api['lockedFields']);

        $update = $this->command('app:song:update');
        self::assertSame(Command::SUCCESS, $update->execute(['public-id' => $song, '--track' => '2', '--explicit' => true, '--json' => true]), $update->getDisplay());
        self::assertSame($api, $this->decode($update));

        $locked = $this->command('app:song:update');
        self::assertSame(Command::INVALID, $locked->execute(['public-id' => $song, '--title' => 'Something']));
        self::assertStringContainsString('Field "title" is locked and cannot be updated.', $locked->getDisplay());
    }

    public function testAMovieUpdateTakesTitleYearAndSummaryAndPrintsTheApiData(): void
    {
        $admin = $this->createAdminUser();
        $movie = $this->movie();
        $changes = ['title' => 'Help!', 'year' => 1965, 'summary' => 'The Beatles on the run.'];

        $api = $this->assertJsonResponse($this->authenticatedRequest('PATCH', '/api/movies/' . $movie, $admin, $changes), 200, 'data')['data'];

        self::assertFalse((new Application($this->client->getKernel()))->find('app:movie:update')->getDefinition()->hasOption('lock'));
        $update = $this->command('app:movie:update');
        self::assertSame(Command::SUCCESS, $update->execute([
            'public-id' => $movie,
            '--title' => 'Help!',
            '--year' => '1965',
            '--summary' => 'The Beatles on the run.',
            '--json' => true,
        ]), $update->getDisplay());

        $cli = $this->decode($update);
        unset($api['updatedAt'], $cli['updatedAt']);
        self::assertSame($api, $cli);
        $stored = $this->entityManager->getRepository(MovieEntity::class)->findOneBy(['publicId' => PublicId::fromString($movie)]);
        self::assertInstanceOf(MovieEntity::class, $stored);
        $this->entityManager->refresh($stored);
        self::assertSame($changes, ['title' => $stored->getTitle(), 'year' => $stored->getYear(), 'summary' => $stored->getSummary()]);
    }

    public function testArtistCreateAnswersCreatedWithDataAndTheCommandPrintsTheSameResource(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->authenticatedRequest('POST', '/api/artists/', $admin, ['name' => 'The Beatles', 'country' => 'GB', 'sortName' => 'Beatles, The']);
        $api = $this->assertJsonResponse($response, 201, 'data')['data'];

        $create = $this->command('app:artist:create');
        self::assertSame(Command::SUCCESS, $create->execute(['name' => 'The Beatles', '--country' => 'GB', '--sort-name' => 'Beatles, The', '--json' => true]), $create->getDisplay());
        $cli = $this->decode($create);

        self::assertSame(array_keys($api), array_keys($cli));
        self::assertNotSame($api['publicId'], $cli['publicId']);
        $identity = ['uuid' => null, 'publicId' => null, 'createdAt' => null];
        self::assertSame(array_diff_key($api, $identity), array_diff_key($cli, $identity));

        $httpUpdate = $this->assertJsonResponse(
            $this->authenticatedRequest('PATCH', '/api/artists/' . $api['publicId'], $admin, ['type' => 'Group', 'lockedFields' => ['name']]),
            200,
            'data',
        )['data'];
        $update = $this->command('app:artist:update');
        self::assertSame(Command::SUCCESS, $update->execute(['public-id' => $cli['publicId'], '--type' => 'Group', '--lock' => ['name'], '--json' => true]), $update->getDisplay());
        self::assertSame(array_diff_key($httpUpdate, $identity), array_diff_key($this->decode($update), $identity));

        foreach ([$api['publicId'], $cli['publicId']] as $publicId) {
            $stored = $this->entityManager->getRepository(ArtistEntity::class)->findOneBy(['publicId' => PublicId::fromString($publicId)]);
            self::assertInstanceOf(ArtistEntity::class, $stored);
            $this->entityManager->refresh($stored);
            self::assertSame(['Group', ['name']], [$stored->getType(), $stored->getLockedFields()]);
        }

        $blank = $this->command('app:artist:create');
        self::assertSame(Command::INVALID, $blank->execute(['name' => '  ']));
    }

    /** @param list<string> $locked */
    private function album(array $locked = []): string
    {
        $album = new AlbumEntity(new PublicId(), $this->library(), 'Fixture album', 'album');
        $album->setLockedFields($locked);
        $this->entityManager->persist($album);
        $this->entityManager->flush();

        return $album->getPublicId()->toString();
    }

    private function song(): string
    {
        $library = $this->library();
        $album = new AlbumEntity(new PublicId(), $library, 'Fixture album', 'album');
        $song = new SongEntity(new PublicId(), $album, 'Fixture song', $library->getPath() . '/song.flac', 1, 'audio/flac');
        $this->entityManager->persist($album);
        $this->entityManager->persist($song);
        $this->entityManager->flush();

        return $song->getPublicId()->toString();
    }

    private function movie(): string
    {
        $movie = new MovieEntity(new PublicId(), $this->library('movie'), 'Fixture movie');
        $this->entityManager->persist($movie);
        $this->entityManager->flush();

        return $movie->getPublicId()->toString();
    }

    private function library(string $type = 'music'): LibraryEntity
    {
        $suffix = bin2hex(random_bytes(8));
        $library = new LibraryEntity('Metadata fixture', 'metadata-' . $suffix, '/tmp/metadata-' . $suffix, $type, 'local');
        $this->entityManager->persist($library);

        return $library;
    }

    /** @param list<string> $fields */
    private function lock(string $album, array $fields): void
    {
        $entity = $this->albumEntity($album);
        $entity->setLockedFields($fields);
        $this->entityManager->flush();
    }

    private function albumEntity(string $publicId): AlbumEntity
    {
        $album = $this->entityManager->getRepository(AlbumEntity::class)->findOneBy(['publicId' => PublicId::fromString($publicId)]);
        self::assertInstanceOf(AlbumEntity::class, $album);
        $this->entityManager->refresh($album);

        return $album;
    }

    /** @return array<string, mixed> */
    private function decode(CommandTester $command): array
    {
        $decoded = json_decode($command->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function command(string $name): CommandTester
    {
        return new CommandTester((new Application($this->client->getKernel()))->find($name));
    }
}
