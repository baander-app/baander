<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\AlbumEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\SongEntity;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Playlist\Application\Command\AddCollaboratorCommand;
use App\Playlist\Application\Command\AddSongCommand;
use App\Playlist\Application\CommandHandler\AddSongHandler;
use App\Playlist\Application\Port\PlaylistSongVisibilityInterface;
use App\Playlist\Application\Command\ReorderSongsCommand;
use App\Playlist\Application\CommandHandler\ReorderSongsHandler;
use App\Playlist\Domain\Model\Playlist;
use App\Playlist\Infrastructure\Doctrine\Repository\PlaylistRepository;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use App\Tests\Support\FinalSchemaBootstrap;
use App\Tests\Fixtures\Playlist\PlaylistWriteRace;
use App\Playlist\Application\Exception\PlaylistWriteConflictException;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

require_once dirname(__DIR__) . '/Fixtures/Playlist/playlist-concurrent-write.php';

final class PlaylistConcurrencyTest extends TestCase
{
    private string $schema;
    private Connection $observer;
    /** @var list<EntityManager> */
    private array $managers = [];
    /** @var list<array{process: resource, input: resource, output: resource, application: string, errors: resource}> */
    private array $children = [];
    private Uuid $playlistId;
    private Uuid $ownerId;
    private Uuid $collaboratorId;
    private PublicId $collaboratorPublicId;
    /** @var list<Uuid> */
    private array $songIds;

    protected function setUp(): void
    {
        if (!getenv('OUTBOX_TEST_DATABASE_URL')) {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL to a disposable PostgreSQL database.');
        }
        $this->schema = 'playlist_concurrency_' . bin2hex(random_bytes(8));
        $this->observer = $this->connect('public');
        $this->observer->executeStatement('CREATE SCHEMA ' . $this->schema);
        $this->observer->executeStatement('SET search_path TO ' . $this->schema . ', public');
        FinalSchemaBootstrap::bootstrap($this->observer);
        $manager = $this->manager();
        $owner = new UserEntity(new PublicId(), 'Owner', 'owner@baander.app', 'hash', '');
        $collaborator = new UserEntity(new PublicId(), 'Collaborator', 'collaborator@baander.app', 'hash', '');
        $library = new LibraryEntity('Race', 'race', '/race', 'music', 'local');
        $album = new AlbumEntity(new PublicId(), $library, 'Race', 'album');
        $first = new SongEntity(new PublicId(), $album, 'First', '/race/first.flac', 1, 'audio/flac');
        $second = new SongEntity(new PublicId(), $album, 'Second', '/race/second.flac', 2, 'audio/flac');
        foreach ([$owner, $collaborator, $library, $album, $first, $second] as $entity) {
            $manager->persist($entity);
        }
        $manager->flush();
        $this->ownerId = $owner->getId();
        $this->collaboratorId = $collaborator->getId();
        $this->collaboratorPublicId = $collaborator->getPublicId();
        $this->songIds = [$first->getId(), $second->getId()];
        $playlist = Playlist::create('Original', $this->ownerId, isCollaborative: true);
        $playlist->addSong($this->songIds[0], 0);
        $this->repository($manager)->save($playlist);
        $this->playlistId = $playlist->getId();
        $manager->clear();
    }

    protected function tearDown(): void
    {
        foreach ($this->children as $child) {
            if (is_resource($child['process'])) {
                if (proc_get_status($child['process'])['running']) {
                    proc_terminate($child['process'], 9);
                }
                proc_close($child['process']);
            }
            foreach (['input', 'output', 'errors'] as $stream) {
                if (is_resource($child[$stream])) {
                    fclose($child[$stream]);
                }
            }
        }
        foreach ($this->managers as $manager) {
            $connection = $manager->getConnection();
            while ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $manager->close();
            $connection->close();
        }
        if (isset($this->observer)) {
            while ($this->observer->isTransactionActive()) {
                $this->observer->rollBack();
            }
            $this->observer->executeStatement('DROP SCHEMA IF EXISTS ' . $this->schema . ' CASCADE');
            $this->observer->close();
        }
    }

    public function testAppendRefreshesTheControllerIdentityMapAfterAnIndependentCommit(): void
    {
        $repository = $this->repository($this->manager());
        $initial = $repository->findByPublicId($this->load($repository)->getPublicId());
        self::assertNotNull($initial);
        $winnerRepository = $this->repository($this->manager());
        $winner = $this->load($winnerRepository);
        $winner->updateMetadata('Winner', 'Retain this description', true, true);
        $winner->addCollaborator($this->collaboratorId);
        $winner->addSong($this->songIds[1], 1);
        $winnerRepository->save($winner);
        $requested = $this->additionalSong();
        $visibility = $this->createStub(PlaylistSongVisibilityInterface::class);
        $visibility->method('isVisible')->willReturn(true);

        $result = (new AddSongHandler($repository, $visibility))(new AddSongCommand(
            $this->playlistId, $requested, $this->ownerId, LibraryReadScope::unrestricted(),
        ));

        self::assertSame('Winner', $result->getName());
        self::assertSame('Retain this description', $result->getDescription());
        self::assertTrue($result->isCollaborator($this->collaboratorId));
        self::assertSame([0, 1, 2], array_map(static fn ($song): int => $song->getPosition(), $result->getSongs()));
        $fresh = $this->load($this->repository($this->manager()));
        self::assertSame([$this->songIds[0]->toString(), $this->songIds[1]->toString(), $requested->toString()], array_map(static fn ($song): string => $song->getSongId()->toString(), $fresh->getSongs()));
        self::assertSame($result->getRevision(), $fresh->getRevision());
    }

    /** @return iterable<string, array{bool, bool}> */
    public static function revokedAppendAuthority(): iterable
    {
        yield 'removed collaborator' => [false, false];
        yield 'collaboration disabled' => [true, false];
        yield 'removed collaborator repeats song' => [false, true];
        yield 'disabled collaborator repeats song' => [true, true];
    }

    #[DataProvider('revokedAppendAuthority')]
    public function testAppendRejectsAuthorityRevokedAfterTheControllerRead(bool $disableCollaboration, bool $duplicate): void
    {
        $repository = $this->repository($this->manager());
        $seed = $this->load($repository);
        $seed->addCollaborator($this->collaboratorId);
        $repository->save($seed);
        $initial = $repository->findByPublicId($seed->getPublicId());
        self::assertNotNull($initial);
        self::assertTrue($initial->isCollaborative() && $initial->isCollaborator($this->collaboratorId));
        $winnerRepository = $this->repository($this->manager());
        $winner = $this->load($winnerRepository);
        if ($disableCollaboration) {
            $winner->updateMetadata('Closed', null, false, false);
        } else {
            $winner->removeCollaborator($this->collaboratorId);
        }
        $winnerRepository->save($winner);
        $before = $this->storedState();
        $visibility = $this->createMock(PlaylistSongVisibilityInterface::class);
        $visibility->expects(self::never())->method('isVisible');

        try {
            (new AddSongHandler($repository, $visibility))(new AddSongCommand(
                $this->playlistId, $this->songIds[$duplicate ? 0 : 1], $this->collaboratorId, LibraryReadScope::unrestricted(),
            ));
            self::fail('The refreshed playlist must deny a former editor, including duplicate requests.');
        } catch (AccessDeniedException) {
            self::assertSame($before, $this->storedState());
        }
    }

    public function testAppendStillConflictsWhenAnotherWriterCommitsAfterTheMutationRead(): void
    {
        $repository = $this->repository($this->manager());
        $requested = $this->additionalSong();
        $winnerState = null;
        $visibility = $this->createMock(PlaylistSongVisibilityInterface::class);
        $visibility->expects(self::once())->method('isVisible')->willReturnCallback(function () use (&$winnerState): bool {
            $winnerRepository = $this->repository($this->manager());
            $winner = $this->load($winnerRepository);
            $winner->updateMetadata('Committed after mutation read', null, false, true);
            $winner->addSong($this->songIds[1], 1);
            $winnerRepository->save($winner);
            $winnerState = $this->storedState();

            return true;
        });

        try {
            (new AddSongHandler($repository, $visibility))(new AddSongCommand(
                $this->playlistId, $requested, $this->ownerId, LibraryReadScope::unrestricted(),
            ));
            self::fail('An independent write after the mutation read must remain a conflict.');
        } catch (PlaylistWriteConflictException) {
            self::assertIsArray($winnerState);
            self::assertSame($winnerState, $this->storedState());
        }
    }

    public function testAppendFollowsTheLastPositionAfterACatalogDeletionCascade(): void
    {
        $repository = $this->repository($this->manager());
        $playlist = $this->load($repository);
        $survivor = $this->additionalSong();
        $playlist->addSong($this->songIds[1], 1);
        $playlist->addSong($survivor, 2);
        $repository->save($playlist);
        $this->observer->executeStatement('DELETE FROM songs WHERE id = ?', [$this->songIds[1]->toString()]);
        $requested = $this->additionalSong();
        $visibility = $this->createStub(PlaylistSongVisibilityInterface::class);
        $visibility->method('isVisible')->willReturn(true);

        $result = (new AddSongHandler($repository, $visibility))(new AddSongCommand(
            $this->playlistId, $requested, $this->ownerId, LibraryReadScope::unrestricted(),
        ));

        self::assertSame([0, 2, 3], array_map(static fn ($song): int => $song->getPosition(), $result->getSongs()));
        $fresh = $this->load($this->repository($this->manager()));
        self::assertSame([$this->songIds[0]->toString(), $survivor->toString(), $requested->toString()], array_map(static fn ($song): string => $song->getSongId()->toString(), $fresh->getSongs()));
        self::assertSame([0, 2, 3], array_map(static fn ($song): int => $song->getPosition(), $fresh->getSongs()));
    }

    public function testAppendReportsNotFoundWhenThePlaylistWasDeletedAfterTheControllerRead(): void
    {
        $repository = $this->repository($this->manager());
        $initial = $repository->findByPublicId($this->load($repository)->getPublicId());
        self::assertNotNull($initial);
        $winnerRepository = $this->repository($this->manager());
        $winnerRepository->delete($this->load($winnerRepository));
        $before = $this->storedState();
        self::assertFalse($before['playlist']);
        $visibility = $this->createMock(PlaylistSongVisibilityInterface::class);
        $visibility->expects(self::never())->method('isVisible');

        try {
            (new AddSongHandler($repository, $visibility))(new AddSongCommand(
                $this->playlistId, $this->songIds[1], $this->ownerId, LibraryReadScope::unrestricted(),
            ));
            self::fail('A playlist deleted before the mutation read must be not found.');
        } catch (NotFoundException) {
            self::assertSame($before, $this->storedState());
        }
    }

    public function testStaleFullyLoadedSaveCannotOverwriteNewerSongsAndCollaborators(): void
    {
        $winnerRepository = $this->repository($this->manager());
        $staleRepository = $this->repository($this->manager());
        $winner = $this->load($winnerRepository);
        $stale = $this->load($staleRepository);
        $winner->updateMetadata(name: 'Winner', description: null, isPublic: false, isCollaborative: true);
        $winner->addSong($this->songIds[1], 1);
        $winner->addCollaborator($this->collaboratorId);
        $winnerRepository->save($winner);
        $before = $this->storedState();
        $stale->updateMetadata(name: 'Stale', description: null, isPublic: false, isCollaborative: true);

        try {
            $staleRepository->save($stale);
            self::fail('A stale fully loaded aggregate must fail before replacing the winner’s songs or collaborators.');
        } catch (ConflictException) {
            self::assertSame($before, $this->storedState());
        }
    }

    public function testDuplicateConcurrentCollaboratorsConflictBeforeAnyChildInsert(): void
    {
        $manager = $this->manager();
        $repository = $this->repository($manager);
        $winner = $this->load($repository);
        $child = $this->startChild('collaborator', $this->collaboratorId);
        $manager->getConnection()->beginTransaction();
        $winner->addCollaborator($this->collaboratorId);
        $repository->save($winner);
        $this->release($child);
        $this->awaitLockWait($child, $manager->getConnection());
        $manager->getConnection()->commit();

        self::assertSame(['outcome' => 'conflict', 'exception' => PlaylistWriteConflictException::class], $this->finish($child));
        self::assertSame(1, (int) $this->observer->fetchOne('SELECT count(*) FROM playlist_collaborators WHERE playlist_id = ?', [$this->playlistId->toString()]));
        $fresh = $this->load($this->repository($this->manager()));
        self::assertTrue($fresh->isCollaborator($this->collaboratorId));
        self::assertSame($winner->getRevision(), $fresh->getRevision());
    }

    public function testConcurrentReorderCannotAlterTheWinnersOrderOrRevision(): void
    {
        $manager = $this->manager();
        $repository = $this->repository($manager);
        $playlist = $this->load($repository);
        $playlist->addSong($this->songIds[1], 1);
        $repository->save($playlist);
        $child = $this->startChild('reorder', implode(',', array_map(static fn (Uuid $id): string => $id->toString(), $this->songIds)));
        $manager->getConnection()->beginTransaction();
        $playlist->reorderSongs(array_reverse($this->songIds));
        $repository->save($playlist);
        $this->release($child);
        $this->awaitLockWait($child, $manager->getConnection());
        $manager->getConnection()->commit();
        $before = $this->storedState();

        self::assertSame(['outcome' => 'conflict', 'exception' => PlaylistWriteConflictException::class], $this->finish($child));
        self::assertSame($before, $this->storedState());
        $fresh = $this->load($this->repository($this->manager()));
        self::assertSame($playlist->getRevision(), $fresh->getRevision());
        self::assertSame(array_reverse(array_map(static fn (Uuid $id): string => $id->toString(), $this->songIds)), array_map(static fn ($song): string => $song->getSongId()->toString(), $fresh->getSongs()));
    }

    /** @return iterable<string, array{bool}> */
    public static function collaboratorAdditions(): iterable
    {
        yield 'same collaborator' => [true];
        yield 'different collaborators' => [false];
    }

    #[DataProvider('collaboratorAdditions')]
    public function testConcurrentCollaboratorHandlersBothSucceedAndPreserveExistingData(bool $sameCollaborator): void
    {
        $manager = $this->manager();
        $repository = $this->repository($manager);
        $playlist = $this->load($repository);
        $playlist->updateMetadata('Shared playlist', 'Keep this description', true, true);
        $playlist->addSong($this->songIds[1], 1);
        $repository->save($playlist);
        $childPublicId = $this->collaboratorPublicId;
        $expectedCollaborators = [$this->collaboratorId->toString()];
        if (!$sameCollaborator) {
            $other = new UserEntity(new PublicId(), 'Other collaborator', 'other@baander.app', 'hash', '');
            $manager->persist($other);
            $manager->flush();
            $childPublicId = $other->getPublicId();
            $expectedCollaborators[] = $other->getId()->toString();
        }
        $before = $this->storedState();
        $child = $this->startChild('handler-collaborator', $childPublicId->toString());
        $connection = $manager->getConnection();
        $connection->beginTransaction();
        PlaylistWriteRace::collaboratorHandler($repository, $connection)(new AddCollaboratorCommand($this->playlistId, $this->collaboratorPublicId, $this->ownerId));
        $winnerRevision = $this->load($repository)->getRevision();
        $this->release($child);
        $this->awaitLockWait($child, $connection);
        $connection->commit();

        self::assertSame(['outcome' => 'saved'], $this->finish($child));
        $after = $this->storedState();
        self::assertSame($before['songs'], $after['songs']);
        foreach (['id', 'public_id', 'user_id', 'name', 'description', 'is_public', 'is_collaborative'] as $column) {
            self::assertSame($before['playlist'][$column], $after['playlist'][$column], $column);
        }
        sort($expectedCollaborators);
        self::assertSame($expectedCollaborators, array_column($after['collaborators'], 'user_id'));
        $fresh = $this->load($this->repository($this->manager()));
        if ($sameCollaborator) {
            self::assertSame($winnerRevision, $fresh->getRevision(), 'Retrying an existing membership must not write again.');
        } else {
            self::assertNotSame($winnerRevision, $fresh->getRevision());
        }
    }

    public function testConcurrentReorderHandlersKeepTheWinnerAndRejectTheSameClientRevision(): void
    {
        $manager = $this->manager();
        $repository = $this->repository($manager);
        $playlist = $this->load($repository);
        $playlist->addSong($this->songIds[1], 1);
        $repository->save($playlist);
        $expectedRevision = $playlist->getRevision();
        $child = $this->startChild('handler-reorder', implode(',', array_map(static fn (Uuid $id): string => $id->toString(), $this->songIds)));
        $connection = $manager->getConnection();
        $connection->beginTransaction();
        $winnerRevision = (new ReorderSongsHandler($repository))(new ReorderSongsCommand($this->playlistId, $this->ownerId, array_reverse($this->songIds), $expectedRevision));
        $this->release($child);
        $this->awaitLockWait($child, $connection);
        $connection->commit();
        $before = $this->storedState();

        self::assertSame(['outcome' => 'conflict', 'exception' => PlaylistWriteConflictException::class], $this->finish($child));
        self::assertSame($before, $this->storedState());
        $freshRepository = $this->repository($this->manager());
        $fresh = $this->load($freshRepository);
        self::assertSame($winnerRevision, $fresh->getRevision());
        self::assertNotSame($expectedRevision, $winnerRevision);
        self::assertSame(array_reverse(array_map(static fn (Uuid $id): string => $id->toString(), $this->songIds)), array_map(static fn ($song): string => $song->getSongId()->toString(), $fresh->getSongs()));
        try {
            (new ReorderSongsHandler($freshRepository))(new ReorderSongsCommand($this->playlistId, $this->ownerId, $this->songIds, $expectedRevision));
            self::fail('A reorder starting after the winner commits must also reject its old client revision.');
        } catch (PlaylistWriteConflictException) {
            self::assertSame($before, $this->storedState());
        }
    }

    public function testRolledBackWinnerAllowsTheWaitingOriginalSnapshotToSave(): void
    {
        $manager = $this->manager();
        $repository = $this->repository($manager);
        $winner = $this->load($repository);
        $child = $this->startChild('save');
        $manager->getConnection()->beginTransaction();
        $winner->updateMetadata('Rolled back', null, false, true);
        $repository->save($winner);
        $this->release($child);
        $this->awaitLockWait($child, $manager->getConnection());
        $manager->getConnection()->rollBack();

        self::assertSame(['outcome' => 'saved'], $this->finish($child));
        self::assertSame('Child', $this->load($this->repository($this->manager()))->getName());
    }

    public function testRetainedAggregateAfterOuterRollbackRequiresAFreshReload(): void
    {
        $manager = $this->manager();
        $repository = $this->repository($manager);
        $retained = $this->load($repository);
        $originalRevision = $retained->getRevision();
        $before = $this->storedState();
        $manager->getConnection()->beginTransaction();
        $retained->updateMetadata('Rolled back', null, false, true);
        $repository->save($retained);
        $savedRevision = $retained->getRevision();
        self::assertNotSame($originalRevision, $savedRevision);
        $manager->getConnection()->rollBack();

        self::assertSame($savedRevision, $retained->getRevision(), 'An enclosing rollback does not rewind the retained domain object.');
        self::assertSame('Rolled back', $retained->getName());
        self::assertSame($before, $this->storedState());
        try {
            $repository->save($retained);
            self::fail('The retained aggregate refers to a persistence version that was rolled back.');
        } catch (PlaylistWriteConflictException) {
            self::assertSame($before, $this->storedState());
            self::assertFalse($manager->getConnection()->isTransactionActive());
        }
        $fresh = $this->load($repository);
        self::assertSame($originalRevision, $fresh->getRevision());
        self::assertSame('Original', $fresh->getName());
        $fresh->updateMetadata('Reloaded after rollback', null, false, true);
        $repository->save($fresh);
        self::assertSame('Reloaded after rollback', $this->load($this->repository($this->manager()))->getName());
    }

    public function testDeleteWaitsOnTheParentBeforeRemovingChildren(): void
    {
        $manager = $this->manager();
        $repository = $this->repository($manager);
        $playlist = $this->load($repository);
        $child = $this->startChild('delete');
        $manager->getConnection()->beginTransaction();
        $playlist->addSong($this->songIds[1], 1);
        $repository->save($playlist);
        $this->release($child);
        $this->awaitLockWait($child, $manager->getConnection());
        self::assertSame(1, (int) $this->observer->fetchOne('SELECT count(*) FROM playlist_song WHERE playlist_id = ?', [$this->playlistId->toString()]));
        $manager->getConnection()->commit();

        self::assertSame(['outcome' => 'saved'], $this->finish($child));
        self::assertFalse($this->observer->fetchOne('SELECT 1 FROM playlists WHERE id = ?', [$this->playlistId->toString()]));
        self::assertSame([], $this->storedState()['songs']);
    }

    public function testDeleteDoesNotLockChildrenWhileWaitingForTheParent(): void
    {
        $manager = $this->manager();
        $child = $this->startChild('delete');
        $connection = $manager->getConnection();
        $connection->beginTransaction();
        $connection->fetchOne('SELECT id FROM playlists WHERE id = ? FOR UPDATE', [$this->playlistId->toString()]);
        $this->release($child);
        $this->awaitLockWait($child, $connection);
        // A third session can still lock the children: the waiting delete has touched none.
        $this->observer->beginTransaction();
        self::assertCount(1, $this->observer->fetchAllAssociative('SELECT id FROM playlist_song WHERE playlist_id = ? FOR UPDATE NOWAIT', [$this->playlistId->toString()]));
        $this->observer->commit();
        $connection->commit();

        self::assertSame(['outcome' => 'saved'], $this->finish($child));
    }

    public function testSaveWaitingOnDeleteCannotRecreateThePlaylist(): void
    {
        $manager = $this->manager();
        $repository = $this->repository($manager);
        $playlist = $this->load($repository);
        $child = $this->startChild('save');
        $manager->getConnection()->beginTransaction();
        $repository->delete($playlist);
        $this->release($child);
        $this->awaitLockWait($child, $manager->getConnection());
        $manager->getConnection()->commit();

        self::assertSame(['outcome' => 'conflict', 'exception' => PlaylistWriteConflictException::class], $this->finish($child));
        self::assertFalse($this->observer->fetchOne('SELECT 1 FROM playlists WHERE id = ?', [$this->playlistId->toString()]));
    }

    /** @return iterable<string, array{string}> */
    public static function cascades(): iterable
    {
        yield 'song deletion' => ['song'];
        yield 'collaborator deletion' => ['user'];
    }

    #[DataProvider('cascades')]
    public function testSaveWaitingOnCascadeRejectsItsOriginalMembership(string $kind): void
    {
        $seed = $this->repository($this->manager());
        $playlist = $this->load($seed);
        $playlist->addCollaborator($this->collaboratorId);
        $seed->save($playlist);
        $revision = $playlist->getRevision();
        $target = $kind === 'song' ? $this->songIds[0] : $this->collaboratorId;
        $child = $this->startChild('save');
        $this->observer->beginTransaction();
        $this->observer->executeStatement('DELETE FROM ' . ($kind === 'song' ? 'songs' : 'users') . ' WHERE id = ?', [$target->toString()]);
        $this->release($child);
        $this->awaitLockWait($child, $this->observer);
        $this->observer->commit();
        $before = $this->storedState();

        self::assertSame(['outcome' => 'conflict', 'exception' => PlaylistWriteConflictException::class], $this->finish($child));
        self::assertSame($before, $this->storedState());
        self::assertNotSame($revision, $this->load($this->repository($this->manager()))->getRevision());
    }

    #[DataProvider('cascades')]
    public function testCascadeWaitsForSaveAndThenInvalidatesItsRevision(string $kind): void
    {
        $manager = $this->manager();
        $repository = $this->repository($manager);
        $playlist = $this->load($repository);
        $playlist->addCollaborator($this->collaboratorId);
        $repository->save($playlist);
        $target = $kind === 'song' ? $this->songIds[0] : $this->collaboratorId;
        $child = $this->startChild('cascade-' . $kind, $target);
        $manager->getConnection()->beginTransaction();
        $playlist->updateMetadata('Saved before cascade', null, false, true);
        $repository->save($playlist);
        $revision = $playlist->getRevision();
        $this->release($child);
        $this->awaitLockWait($child, $manager->getConnection());
        $manager->getConnection()->commit();

        self::assertSame(['outcome' => 'saved'], $this->finish($child));
        $fresh = $this->load($this->repository($this->manager()));
        self::assertSame('Saved before cascade', $fresh->getName());
        self::assertNotSame($revision, $fresh->getRevision());
        if ($kind === 'song') {
            self::assertSame([], $fresh->getSongs());
        } else {
            self::assertFalse($fresh->isCollaborator($this->collaboratorId));
        }
    }

    public function testRejectedStaleSaveClearsTheManagerForFreshReloadAndWrite(): void
    {
        $manager = $this->manager();
        $repository = $this->repository($manager);
        $stale = $this->load($repository);
        $winnerRepository = $this->repository($this->manager());
        $winner = $this->load($winnerRepository);
        $winner->addCollaborator($this->collaboratorId);
        $winnerRepository->save($winner);
        $before = $this->storedState();
        $stale->updateMetadata('Rejected', null, false, true);
        try {
            $repository->save($stale);
            self::fail('Stale save must fail.');
        } catch (PlaylistWriteConflictException) {
            self::assertSame($before, $this->storedState());
            self::assertFalse($manager->getConnection()->isTransactionActive());
        }
        $fresh = $this->load($repository);
        self::assertSame($winner->getRevision(), $fresh->getRevision());
        self::assertTrue($fresh->isCollaborator($this->collaboratorId));
        $fresh->updateMetadata('Fresh request', null, false, true);
        $repository->save($fresh);
        self::assertSame('Fresh request', $this->load($this->repository($this->manager()))->getName());
    }

    public function testFlushFailureRollsBackParentAndChildrenAndAllowsANewPersistenceContext(): void
    {
        $manager = $this->manager();
        $repository = $this->repository($manager);
        $playlist = $this->load($repository);
        $before = $this->storedState();
        $playlist->updateMetadata('Must roll back', null, false, true);
        $playlist->addSong(new Uuid(), 1);
        try {
            $repository->save($playlist);
            self::fail('An absent catalog song must fail its foreign key.');
        } catch (ForeignKeyConstraintViolationException) {
            self::assertSame($before, $this->storedState());
            self::assertFalse($manager->getConnection()->isTransactionActive());
        }
        $freshRepository = $this->repository($this->manager());
        $fresh = $this->load($freshRepository);
        $fresh->updateMetadata('Recovered', null, false, true);
        $freshRepository->save($fresh);
        self::assertSame('Recovered', $this->load($this->repository($this->manager()))->getName());
    }

    public function testMetadataOnlyProjectionCannotDeleteStoredMemberships(): void
    {
        $repository = $this->repository($this->manager());
        $projection = $repository->findByUser($this->ownerId)[0];
        $before = $this->storedState();
        $projection->updateMetadata('Projection', null, false, true);
        try {
            $repository->save($projection);
            self::fail('Metadata-only projections cannot be saved as complete aggregate snapshots.');
        } catch (\LogicException) {
            self::assertSame($before, $this->storedState());
        }
    }

    /** @return array{process: resource, input: resource, output: resource, application: string, errors: resource} */
    private function startChild(string $action, Uuid|string|null $target = null): array
    {
        $application = 'playlist_write_' . bin2hex(random_bytes(8));
        $output = tmpfile();
        self::assertIsResource($output);
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/Fixtures/Playlist/playlist-concurrent-write.php', $this->schema, $application, $this->playlistId->toString(), $action, $target instanceof Uuid ? $target->toString() : ($target ?? '')],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => $output],
            $pipes,
        );
        self::assertIsResource($process);
        $child = ['process' => $process, 'input' => $pipes[0], 'output' => $pipes[1], 'application' => $application, 'errors' => $output];
        $this->children[] = $child;
        $read = [$pipes[1]];
        $write = [];
        $except = [];
        self::assertSame(1, stream_select($read, $write, $except, 10), 'Child must finish loading before the parent writes.');
        $ready = fgets($pipes[1]);
        if ($ready !== "ready\n") {
            rewind($output);
            self::fail('Child did not load its playlist: ' . stream_get_contents($output));
        }
        // Keep stdout separately: stderr is only diagnostics and can never corrupt the result.
        return $child;
    }

    /** @param array{process: resource, input: resource, output: resource, application: string, errors: resource} $child */
    private function release(array $child): void
    {
        self::assertSame(3, fwrite($child['input'], "go\n"));
        fflush($child['input']);
        fclose($child['input']);
    }

    /** @param array{process: resource, input: resource, output: resource, application: string, errors: resource} $child */
    private function awaitLockWait(array $child, Connection $blocker): void
    {
        $blockerPid = (int) $blocker->fetchOne('SELECT pg_backend_pid()');
        $deadline = microtime(true) + 8;
        while (microtime(true) < $deadline) {
            $this->observer->executeQuery('SELECT pg_stat_clear_snapshot()')->free();
            $waiting = $this->observer->fetchOne(
                "SELECT 1 FROM pg_stat_activity WHERE application_name = ? AND wait_event_type = 'Lock' AND ? = ANY(pg_blocking_pids(pid))",
                [$child['application'], $blockerPid],
            );
            if ($waiting !== false) {
                return;
            }
            if (!proc_get_status($child['process'])['running']) {
                break;
            }
            usleep(1000);
        }
        self::fail('The competing operation must wait for the first session’s transaction lock.');
    }

    /**
     * @param array{process: resource, input: resource, output: resource, application: string, errors: resource} $child
     * @return array<string, mixed>
     */
    private function finish(array $child): array
    {
        $read = [$child['output']];
        $write = [];
        $except = [];
        self::assertSame(1, stream_select($read, $write, $except, 10), 'The released child must finish.');
        $result = (string) stream_get_contents($child['output']);
        fclose($child['output']);
        rewind($child['errors']);
        $errors = (string) stream_get_contents($child['errors']);
        fclose($child['errors']);
        self::assertSame(0, proc_close($child['process']), $errors . $result);
        $decoded = json_decode($result, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function connect(string $schema): Connection
    {
        return PlaylistWriteRace::connect($schema);
    }

    private function additionalSong(): Uuid
    {
        $manager = $this->manager();
        $album = $manager->getRepository(AlbumEntity::class)->findOneBy([]);
        self::assertInstanceOf(AlbumEntity::class, $album);
        $song = new SongEntity(new PublicId(), $album, 'Additional', '/race/' . (new Uuid())->toString() . '.flac', 3, 'audio/flac');
        $manager->persist($song);
        $manager->flush();

        return $song->getId();
    }

    private function manager(): EntityManager
    {
        $manager = PlaylistWriteRace::manager($this->connect($this->schema));
        $this->managers[] = $manager;

        return $manager;
    }

    private function repository(EntityManager $manager): PlaylistRepository
    {
        return PlaylistWriteRace::repository($manager);
    }

    private function load(PlaylistRepository $repository): Playlist
    {
        $playlist = $repository->findWithSongs($this->playlistId);
        self::assertNotNull($playlist);

        return $playlist;
    }

    /** @return array<string, mixed> */
    private function storedState(): array
    {
        $id = $this->playlistId->toString();

        return [
            'playlist' => $this->observer->fetchAssociative('SELECT * FROM playlists WHERE id = ?', [$id]),
            'songs' => $this->observer->fetchAllAssociative('SELECT * FROM playlist_song WHERE playlist_id = ? ORDER BY position, song_id', [$id]),
            'collaborators' => $this->observer->fetchAllAssociative('SELECT * FROM playlist_collaborators WHERE playlist_id = ? ORDER BY user_id', [$id]),
        ];
    }
}
