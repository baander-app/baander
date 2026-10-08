<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalog\Interface\Console;

use App\Auth\Domain\Model\User;
use App\Catalog\Infrastructure\Doctrine\Entity\AlbumEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\GenreAlbumEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\GenreEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\GenreSongEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\SongEntity;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Functional\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The app:genre:* commands and GenreController reach the same use cases and report the same outcomes.
 */
final class GenreCommandsTest extends TestCase
{
    private const string CYCLE_MESSAGE = 'Cannot set parent: would create a circular reference.';

    public function testMakingAGenreTheChildOfItsDescendantIsRejectedOnBothPathsWithTheSameError(): void
    {
        $admin = $this->createAdminUser();
        $rock = $this->createGenreOverHttp($admin, ['name' => 'Rock', 'slug' => 'rock']);
        $hardRock = $this->createGenreOverHttp($admin, ['name' => 'Hard Rock', 'slug' => 'hard-rock', 'parentId' => $rock['uuid']]);
        $grunge = $this->createGenreOverHttp($admin, ['name' => 'Grunge', 'slug' => 'grunge', 'parentId' => $hardRock['uuid']]);

        $error = $this->assertJsonResponse(
            $this->authenticatedRequest('PATCH', '/api/genres/rock', $admin, ['parentId' => $grunge['uuid']]),
            422,
        );
        self::assertSame(self::CYCLE_MESSAGE, $error['error']['message']);

        $update = $this->command('app:genre:update');
        self::assertSame(Command::INVALID, $update->execute(['slug' => 'rock', '--parent' => $grunge['uuid']]));
        self::assertStringContainsString(self::CYCLE_MESSAGE, $this->normalized($update->getDisplay()));

        self::assertNull($this->genre('rock')->getParent());
    }

    public function testCreatingAGenreWithAnUnknownParentIsRejectedOnBothPaths(): void
    {
        $admin = $this->createAdminUser();
        $unknown = (new Uuid())->toString();

        $error = $this->assertJsonResponse(
            $this->authenticatedRequest('POST', '/api/genres/', $admin, ['name' => 'Orphan', 'slug' => 'orphan', 'parentId' => $unknown]),
            422,
        );
        self::assertSame('Parent genre not found.', $error['error']['message']);

        $create = $this->command('app:genre:create');
        self::assertSame(Command::INVALID, $create->execute(['name' => 'Orphan', 'slug' => 'orphan', '--parent' => $unknown]));
        self::assertStringContainsString('Parent genre not found.', $create->getDisplay());

        self::assertNull($this->entityManager->getRepository(GenreEntity::class)->findOneBy(['slug' => 'orphan']));
    }

    public function testConsoleCreateAndUpdateStoreWhatTheApiStores(): void
    {
        $admin = $this->createAdminUser();
        $rock = $this->createGenreOverHttp($admin, ['name' => 'Rock', 'slug' => 'rock']);

        $create = $this->command('app:genre:create');
        self::assertSame(Command::SUCCESS, $create->execute(['name' => 'Blues', 'slug' => 'blues']), $create->getDisplay());
        $update = $this->command('app:genre:update');
        self::assertSame(Command::SUCCESS, $update->execute([
            'slug' => 'blues',
            '--name' => 'Blues Rock',
            '--slug' => 'blues-rock',
            '--parent' => $rock['uuid'],
        ]), $update->getDisplay());

        $this->entityManager->clear();
        $bluesRock = $this->genre('blues-rock');
        self::assertSame('Blues Rock', $bluesRock->getName());
        self::assertSame($rock['uuid'], $bluesRock->getParent()?->getId()->toString());

        $missing = $this->command('app:genre:update');
        self::assertSame(Command::FAILURE, $missing->execute(['slug' => 'blues', '--name' => 'X']));
        $this->assertJsonResponse($this->authenticatedRequest('PATCH', '/api/genres/blues', $admin, ['name' => 'X']), 404);
    }

    public function testListTreeShowsEveryGenreFromTheShell(): void
    {
        $admin = $this->createAdminUser();
        $rock = $this->createGenreOverHttp($admin, ['name' => 'Rock', 'slug' => 'rock']);
        $this->createGenreOverHttp($admin, ['name' => 'Hard Rock', 'slug' => 'hard-rock', 'parentId' => $rock['uuid']]);
        $this->createGenreOverHttp($admin, ['name' => 'Jazz', 'slug' => 'jazz']);

        // No genre has library media, so only the unrestricted scope can see them.
        $tree = $this->command('app:genre:list');
        self::assertSame(Command::SUCCESS, $tree->execute(['--tree' => true]));
        $lines = array_values(array_filter(array_map('rtrim', explode("\n", $tree->getDisplay()))));
        self::assertSame(['Jazz (jazz)', 'Rock (rock)', '  Hard Rock (hard-rock)'], $lines);

        $json = $this->command('app:genre:list');
        self::assertSame(Command::SUCCESS, $json->execute(['--json' => true]));
        $api = $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/genres/?flat=true', $admin), 200, 'data');
        self::assertSame($api['data'], json_decode($json->getDisplay(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function testAddingAnAlbumToAGenreTwiceLeavesOneLink(): void
    {
        $admin = $this->createAdminUser();
        $this->createGenreOverHttp($admin, ['name' => 'Rock', 'slug' => 'rock']);
        [$album, $song] = $this->albumWithSong();

        $this->assertNoContent($this->authenticatedRequest('POST', '/api/genres/rock/albums', $admin, ['albumId' => $album]));
        $add = $this->command('app:genre:album:add');
        self::assertSame(Command::SUCCESS, $add->execute(['slug' => 'rock', 'album-id' => $album]), $add->getDisplay());
        self::assertSame(1, $this->links(GenreAlbumEntity::class));

        $remove = $this->command('app:genre:album:remove');
        self::assertSame(Command::SUCCESS, $remove->execute(['slug' => 'rock', 'album-id' => $album]), $remove->getDisplay());
        self::assertSame(0, $this->links(GenreAlbumEntity::class));

        $songAdd = $this->command('app:genre:song:add');
        self::assertSame(Command::SUCCESS, $songAdd->execute(['slug' => 'rock', 'song-id' => $song]), $songAdd->getDisplay());
        $this->assertNoContent($this->authenticatedRequest('POST', '/api/genres/rock/songs', $admin, ['songId' => $song]));
        self::assertSame(1, $this->links(GenreSongEntity::class));
        $songRemove = $this->command('app:genre:song:remove');
        self::assertSame(Command::SUCCESS, $songRemove->execute(['slug' => 'rock', 'song-id' => $song]), $songRemove->getDisplay());
        self::assertSame(0, $this->links(GenreSongEntity::class));

        $unknownGenre = $this->command('app:genre:album:add');
        self::assertSame(Command::FAILURE, $unknownGenre->execute(['slug' => 'nope', 'album-id' => $album]));
        $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/genres/nope/albums', $admin, ['albumId' => $album]), 404);
        $malformed = $this->command('app:genre:album:add');
        self::assertSame(Command::INVALID, $malformed->execute(['slug' => 'rock', 'album-id' => 'not-a-uuid']));
        $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/genres/rock/albums', $admin, ['albumId' => 'not-a-uuid']), 400);
    }

    public function testATakenSlugIsAConflictOnBothPaths(): void
    {
        $admin = $this->createAdminUser();
        $this->createGenreOverHttp($admin, ['name' => 'Rock', 'slug' => 'rock']);
        $this->createGenreOverHttp($admin, ['name' => 'Blues', 'slug' => 'blues']);
        $message = 'A genre with the slug "rock" already exists.';

        $created = $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/genres/', $admin, ['name' => 'Rock again', 'slug' => 'rock']), 409);
        self::assertSame($message, $created['error']['message']);
        $updated = $this->assertJsonResponse($this->authenticatedRequest('PATCH', '/api/genres/blues', $admin, ['slug' => 'rock']), 409);
        self::assertSame($message, $updated['error']['message']);

        $create = $this->command('app:genre:create');
        self::assertSame(Command::FAILURE, $create->execute(['name' => 'Rock again', 'slug' => 'rock']));
        self::assertStringContainsString($message, $this->normalized($create->getDisplay()));
        $update = $this->command('app:genre:update');
        self::assertSame(Command::FAILURE, $update->execute(['slug' => 'blues', '--slug' => 'rock']));
        self::assertStringContainsString($message, $this->normalized($update->getDisplay()));

        self::assertSame(2, $this->entityManager->getRepository(GenreEntity::class)->count([]));
        self::assertSame('Blues', $this->genre('blues')->getName());
    }

    public function testAnUnknownAlbumOrSongIsNotFoundOnBothPaths(): void
    {
        $admin = $this->createAdminUser();
        $this->createGenreOverHttp($admin, ['name' => 'Rock', 'slug' => 'rock']);
        $unknown = (new Uuid())->toString();

        $responses = [
            'Album not found.' => [
                $this->authenticatedRequest('POST', '/api/genres/rock/albums', $admin, ['albumId' => $unknown]),
                $this->authenticatedRequest('DELETE', '/api/genres/rock/albums/' . $unknown, $admin),
            ],
            'Song not found.' => [
                $this->authenticatedRequest('POST', '/api/genres/rock/songs', $admin, ['songId' => $unknown]),
                $this->authenticatedRequest('DELETE', '/api/genres/rock/songs/' . $unknown, $admin),
            ],
        ];
        foreach ($responses as $message => $pair) {
            foreach ($pair as $response) {
                self::assertSame($message, $this->assertJsonResponse($response, 404)['error']['message']);
            }
        }

        foreach ([
            'app:genre:album:add' => ['album-id', 'Album not found.'],
            'app:genre:album:remove' => ['album-id', 'Album not found.'],
            'app:genre:song:add' => ['song-id', 'Song not found.'],
            'app:genre:song:remove' => ['song-id', 'Song not found.'],
        ] as $name => [$argument, $message]) {
            $command = $this->command($name);
            self::assertSame(Command::FAILURE, $command->execute(['slug' => 'rock', $argument => $unknown]), $name);
            self::assertStringContainsString($message, $command->getDisplay(), $name);
        }
    }

    public function testDeleteWithoutATerminalNeedsForce(): void
    {
        $admin = $this->createAdminUser();
        $this->createGenreOverHttp($admin, ['name' => 'Rock', 'slug' => 'rock']);

        $refused = $this->command('app:genre:delete');
        self::assertSame(Command::INVALID, $refused->execute(['slug' => 'rock'], ['interactive' => false]));
        self::assertNotNull($this->entityManager->getRepository(GenreEntity::class)->findOneBy(['slug' => 'rock']));

        $forced = $this->command('app:genre:delete');
        self::assertSame(Command::SUCCESS, $forced->execute(['slug' => 'rock', '--force' => true], ['interactive' => false]), $forced->getDisplay());
        $this->entityManager->clear();
        self::assertNull($this->entityManager->getRepository(GenreEntity::class)->findOneBy(['slug' => 'rock']));

        $missing = $this->command('app:genre:delete');
        self::assertSame(Command::FAILURE, $missing->execute(['slug' => 'rock', '--force' => true], ['interactive' => false]));
        $this->assertJsonResponse($this->authenticatedRequest('DELETE', '/api/genres/rock', $admin), 404);
    }

    /**
     * @param array<string, string> $body
     * @return array<string, mixed>
     */
    private function createGenreOverHttp(User $admin, array $body): array
    {
        return $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/genres/', $admin, $body), 201, 'data')['data'];
    }

    private function genre(string $slug): GenreEntity
    {
        $genre = $this->entityManager->getRepository(GenreEntity::class)->findOneBy(['slug' => $slug]);
        self::assertInstanceOf(GenreEntity::class, $genre);
        $this->entityManager->refresh($genre);

        return $genre;
    }

    /** @return array{string, string} the album and song UUIDs */
    private function albumWithSong(): array
    {
        $suffix = bin2hex(random_bytes(8));
        $library = new LibraryEntity('Genre fixture', 'genre-' . $suffix, '/tmp/genre-' . $suffix, 'music', 'local');
        $album = new AlbumEntity(new PublicId(), $library, 'Genre fixture album', 'album');
        $song = new SongEntity(new PublicId(), $album, 'Genre fixture song', '/tmp/genre-' . $suffix . '/song.flac', 1, 'audio/flac');
        $this->entityManager->persist($library);
        $this->entityManager->persist($album);
        $this->entityManager->persist($song);
        $this->entityManager->flush();

        return [$album->getId()->toString(), $song->getId()->toString()];
    }

    /** @param class-string $link */
    private function links(string $link): int
    {
        $this->entityManager->clear();

        return $this->entityManager->getRepository($link)->count([]);
    }

    private function assertNoContent(\Symfony\Component\HttpFoundation\Response $response): void
    {
        self::assertSame(204, $response->getStatusCode(), (string) $response->getContent());
    }

    private function normalized(string $display): string
    {
        return (string) preg_replace('/\s+/', ' ', $display);
    }

    private function command(string $name): CommandTester
    {
        return new CommandTester((new Application($this->client->getKernel()))->find($name));
    }
}
