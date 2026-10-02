<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Security\Voter;

use App\Auth\Infrastructure\Security\SecurityUser;
use App\Auth\Infrastructure\Security\Voter\AdminVoter;
use App\Auth\Infrastructure\Security\Voter\AlbumVoter;
use App\Auth\Infrastructure\Security\Voter\LibraryVoter;
use App\Auth\Infrastructure\Security\Voter\PlaylistVoter;
use App\Auth\Infrastructure\Security\Voter\SongVoter;
use App\Library\Domain\Model\Library;
use App\Library\Domain\ValueObject\LibraryPath;
use App\Library\Domain\ValueObject\LibrarySlug;
use App\Library\Domain\ValueObject\LibraryType;
use App\Notification\Domain\Model\Notification;
use App\Notification\Domain\ValueObject\NotificationCategory;
use App\Notification\Infrastructure\Security\NotificationVoter;
use App\Playlist\Domain\Model\Playlist;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\FilesystemType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManager;

/** Exercises the real affirmative strategy, where one unrelated grant can override a denial. */
final class VoterIsolationTest extends TestCase
{
    #[DataProvider('resourceAttributes')]
    public function testUnrelatedAdminCannotGainAccessToForeignNotification(string $attribute): void
    {
        $notification = $this->notification(Uuid::v4());
        $admin = $this->token(Uuid::v4(), admin: true);

        $this->assertFalse($this->manager()->decide($admin, [$attribute], $notification));
    }

    public function testOwnerCanAccessTheirNotificationWithAllVotersPresent(): void
    {
        $owner = Uuid::v4();
        $notification = $this->notification($owner);
        foreach (['VIEW', 'EDIT', 'DELETE'] as $attribute) {
            $this->assertTrue($this->manager()->decide($this->token($owner), [$attribute], $notification));
        }
    }

    #[DataProvider('userRoles')]
    public function testUnrelatedDuckTypedObjectCannotGainResourceAccess(bool $admin): void
    {
        $userId = Uuid::v4();
        $subject = new class($userId) {
            public function __construct(private readonly Uuid $userId) {}
            public function getOwnerId(): string { return $this->userId->toString(); }
            public function getUserId(): Uuid { return $this->userId; }
            public function getId(): string { return $this->userId->toString(); }
            public function isCollaborator(string $userId): bool { return true; }
        };

        foreach (['VIEW', 'EDIT', 'DELETE'] as $attribute) {
            $this->assertFalse($this->manager()->decide($this->token($userId, $admin), [$attribute], $subject));
        }
    }

    public function testRealPlaylistRetainsOwnerAndScopedAdminAccess(): void
    {
        $owner = Uuid::v4();
        $playlist = Playlist::create('Voter isolation playlist', $owner);
        foreach (['VIEW', 'EDIT', 'DELETE', 'MANAGE_COLLABORATORS'] as $attribute) {
            $this->assertTrue($this->manager()->decide($this->token($owner), [$attribute], $playlist));
            $this->assertTrue($this->manager()->decide($this->token(Uuid::v4(), admin: true), [$attribute], $playlist));
            $this->assertFalse($this->manager()->decide($this->token(Uuid::v4()), [$attribute], $playlist));
        }
    }

    public function testRealLibraryRetainsScopedAdminAccess(): void
    {
        $library = Library::create(
            'Voter isolation library', new LibrarySlug('voter-isolation'), new LibraryPath('/music'),
            LibraryType::Music, FilesystemType::Local,
        );

        foreach (['VIEW', 'EDIT', 'DELETE'] as $attribute) {
            $this->assertTrue($this->manager()->decide($this->token(Uuid::v4(), admin: true), [$attribute], $library));
        }
    }

    /** @return iterable<string, array{string}> */
    public static function resourceAttributes(): iterable
    {
        yield 'view' => ['VIEW'];
        yield 'edit' => ['EDIT'];
        yield 'delete' => ['DELETE'];
    }

    /** @return iterable<string, array{bool}> */
    public static function userRoles(): iterable
    {
        yield 'ordinary user' => [false];
        yield 'admin' => [true];
    }

    private function manager(): AccessDecisionManager
    {
        return new AccessDecisionManager([
            new NotificationVoter(),
            new LibraryVoter(),
            new PlaylistVoter(),
            new AlbumVoter(),
            new SongVoter(),
            new AdminVoter(),
        ]);
    }

    private function token(Uuid $userId, bool $admin = false): UsernamePasswordToken
    {
        $roles = $admin ? ['ROLE_USER', 'ROLE_ADMIN'] : ['ROLE_USER'];
        $user = new SecurityUser($userId->toString(), 'voter-isolation@baander.app', 'hashed', $roles);

        return new UsernamePasswordToken($user, 'api', $roles);
    }

    private function notification(Uuid $owner): Notification
    {
        return Notification::create($owner, NotificationCategory::Security, 'test.isolation', 'Private title', 'Private body');
    }
}
