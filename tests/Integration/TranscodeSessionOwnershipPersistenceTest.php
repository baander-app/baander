<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Domain\Model\TranscodeSession;
use App\Transcode\Domain\ValueObject\AudioProfile;
use App\Transcode\Infrastructure\Doctrine\Entity\TranscodeJobEntity;
use App\Transcode\Infrastructure\Doctrine\Entity\TranscodeSessionEntity;
use App\Transcode\Infrastructure\Doctrine\Repository\TranscodeSessionRepository;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use PHPUnit\Framework\TestCase;

final class TranscodeSessionOwnershipPersistenceTest extends TestCase
{
    use OwnershipPersistenceHarness;

    public function testOwnerIsAScalarUuidField(): void
    {
        $this->assertScalarUuidOwners([TranscodeSessionEntity::class => ['userId', 'user_id']]);
    }

    public function testDeclaredForeignKeyMatchesTheCatalog(): void
    {
        $this->assertDeclaredForeignKeysMatchCatalog(['transcode_sessions' => ['user_id', 'fk_transcode_sessions_user_id']]);
    }

    public function testSchemaComparisonIsCleanForTranscodeSessions(): void
    {
        $this->assertSchemaComparisonIsClean(['transcode_sessions']);
    }

    public function testLookupsByUserAndByJobStayOwnerScoped(): void
    {
        $first = $this->createUser();
        $second = $this->createUser();
        $shared = $this->createJob();
        $own = $this->createJob();
        $repository = new TranscodeSessionRepository($this->manager);

        $firstShared = $this->session($first, $shared);
        $secondShared = $this->session($second, $shared);
        $firstOwn = $this->session($first, $own);
        $firstOwn->markPreparing();
        $firstDone = $this->session($first, $own);
        $firstDone->markCancelled();
        foreach ([$firstShared, $secondShared, $firstOwn, $firstDone] as $session) {
            $repository->save($session);
        }
        $this->manager->clear();

        $ids = static function (array $sessions): array {
            $values = array_map(static fn (TranscodeSession $session): string => $session->getId()->toString(), $sessions);
            sort($values);

            return $values;
        };
        $expected = static function (TranscodeSession ...$sessions) use ($ids): array {
            return $ids($sessions);
        };

        self::assertSame($expected($firstShared, $firstOwn, $firstDone), $ids($repository->findByUser($first)));
        self::assertSame($expected($secondShared), $ids($repository->findByUser($second)));
        self::assertSame($expected($firstShared, $firstOwn), $ids($repository->findActiveSessions($first)));
        self::assertSame($expected($firstShared, $secondShared), $ids($repository->findByJob($shared)));

        foreach ($repository->findByJob($own) as $session) {
            self::assertTrue($session->getUserId()->equals($first));
            self::assertTrue($session->getJobId()->equals($own));
        }
        $loaded = $repository->findByUuid($secondShared->getId());
        self::assertNotNull($loaded);
        self::assertTrue($loaded->getUserId()->equals($second));
        self::assertTrue($loaded->getJobId()->equals($shared));
    }

    public function testUserDeletionCascadesOnlyThatUsersSessions(): void
    {
        $first = $this->createUser();
        $second = $this->createUser();
        $job = $this->createJob();
        $repository = new TranscodeSessionRepository($this->manager);
        foreach ([$first, $second] as $owner) {
            $repository->save($this->session($owner, $job));
        }
        $this->manager->clear();

        $this->deleteUser($first);

        self::assertSame(0, $this->countOwnedRows('transcode_sessions', 'user_id', $first));
        self::assertSame(1, $this->countOwnedRows('transcode_sessions', 'user_id', $second));
    }

    public function testForeignKeyRejectsAnUnknownOwner(): void
    {
        $job = $this->createJob();

        $this->expectException(ForeignKeyConstraintViolationException::class);

        (new TranscodeSessionRepository($this->manager))->save($this->session(Uuid::generate(), $job));
    }

    private function createJob(): Uuid
    {
        $job = new TranscodeJobEntity(new PublicId(), Uuid::generate(), '1080p');
        $this->manager->persist($job);
        $this->manager->flush();

        return $job->getId();
    }

    private function session(Uuid $owner, Uuid $job): TranscodeSession
    {
        return TranscodeSession::create($owner, $job, Uuid::generate(), AudioProfile::streamingStereo());
    }
}
