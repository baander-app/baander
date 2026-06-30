<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Auth\Domain\Model\User;
use App\Library\Application\Port\LibraryAccessPortInterface;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Functional\TestCase;
use Doctrine\ORM\EntityManagerInterface;

final class LibraryAccessTest extends TestCase
{
    private LibraryAccessPortInterface $libraryAccess;
    private EntityManagerInterface $libraryEm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->libraryAccess = static::getContainer()->get(LibraryAccessPortInterface::class);
        $this->libraryEm = static::getContainer()->get(EntityManagerInterface::class);
    }

    private function createLibrary(): Uuid
    {
        $entity = new LibraryEntity(
            name: 'Test Library ' . bin2hex(random_bytes(4)),
            slug: 'test-' . bin2hex(random_bytes(4)),
            path: '/music/test',
            type: 'music',
            filesystemType: 'local',
        );
        $this->libraryEm->persist($entity);
        $this->libraryEm->flush();

        return $entity->getId();
    }

    public function testGrantCreatesAccess(): void
    {
        $userId = Uuid::fromString($this->createSuperAdminUser()->getId()->toString());
        $libraryId = $this->createLibrary();

        $this->libraryAccess->grant($userId, $libraryId);

        $this->assertTrue($this->libraryAccess->hasAccess($userId, $libraryId));
    }

    public function testGrantIsIdempotent(): void
    {
        $userId = Uuid::fromString($this->createSuperAdminUser()->getId()->toString());
        $libraryId = $this->createLibrary();

        $this->libraryAccess->grant($userId, $libraryId);
        $this->libraryAccess->grant($userId, $libraryId);

        $this->assertTrue($this->libraryAccess->hasAccess($userId, $libraryId));
    }

    public function testRevokeRemovesAccess(): void
    {
        $userId = Uuid::fromString($this->createSuperAdminUser()->getId()->toString());
        $libraryId = $this->createLibrary();

        $this->libraryAccess->grant($userId, $libraryId);
        $this->libraryAccess->revoke($userId, $libraryId);

        $this->assertFalse($this->libraryAccess->hasAccess($userId, $libraryId));
    }

    public function testRevokeIsIdempotent(): void
    {
        $userId = Uuid::fromString($this->createSuperAdminUser()->getId()->toString());
        $libraryId = $this->createLibrary();

        $this->libraryAccess->revoke($userId, $libraryId);
        $this->assertFalse($this->libraryAccess->hasAccess($userId, $libraryId));
    }

    public function testGetUserLibraryIdsReturnsGrantedLibraries(): void
    {
        $userId = Uuid::fromString($this->createSuperAdminUser()->getId()->toString());
        $lib1 = $this->createLibrary();
        $lib2 = $this->createLibrary();

        $this->libraryAccess->grant($userId, $lib1);
        $this->libraryAccess->grant($userId, $lib2);

        $ids = $this->libraryAccess->getUserLibraryIds($userId);

        $this->assertCount(2, $ids);
        $this->assertContains($lib1->toString(), $ids);
        $this->assertContains($lib2->toString(), $ids);
    }

    public function testHasAccessReturnsFalseForUngrantedLibrary(): void
    {
        $userId = Uuid::fromString($this->createSuperAdminUser()->getId()->toString());
        $libraryId = $this->createLibrary();

        $this->assertFalse($this->libraryAccess->hasAccess($userId, $libraryId));
    }
}
