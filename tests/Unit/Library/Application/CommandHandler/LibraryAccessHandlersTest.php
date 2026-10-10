<?php

declare(strict_types=1);

namespace App\Tests\Unit\Library\Application\CommandHandler;

use App\Auth\Application\Port\UserIdentifierResolverInterface;
use App\Library\Application\Command\GrantLibraryAccessCommand;
use App\Library\Application\Command\RevokeLibraryAccessCommand;
use App\Library\Application\CommandHandler\GrantLibraryAccessHandler;
use App\Library\Application\CommandHandler\RevokeLibraryAccessHandler;
use App\Library\Application\DTO\LibraryAccess;
use App\Library\Application\Exception\LibraryNotFoundException;
use App\Library\Application\Port\LibraryAccessPortInterface;
use App\Library\Application\Query\ListLibraryAccessQuery;
use App\Library\Application\QueryHandler\ListLibraryAccessHandler;
use App\Library\Application\Service\LibraryLookup;
use App\Library\Domain\Model\Library;
use App\Library\Domain\ValueObject\LibraryPath;
use App\Library\Domain\ValueObject\LibrarySlug;
use App\Library\Domain\ValueObject\LibraryType;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\FilesystemType;
use App\Tests\Fixtures\Library\InMemoryLibraryRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/** The admin user page and `app:library:member:*` list, grant and revoke library access through these handlers. */
final class LibraryAccessHandlersTest extends TestCase
{
    private const string EMAIL = 'listener@baander.app';

    private InMemoryLibraryRepository $libraries;
    private Library $music;
    private Library $films;
    private Uuid $userId;
    private UserIdentifierResolverInterface $users;
    /** @var LibraryAccessPortInterface&object{memberships: array<string, true>} */
    private LibraryAccessPortInterface $access;

    protected function setUp(): void
    {
        $this->libraries = new InMemoryLibraryRepository(new MockClock());
        $this->music = $this->libraries->add(Library::create('Music', new LibrarySlug('music'), new LibraryPath('/media/music'), LibraryType::Music, FilesystemType::Local));
        $this->films = $this->libraries->add(Library::create('Films', new LibrarySlug('films'), new LibraryPath('/media/films'), LibraryType::Movie, FilesystemType::Local));
        $this->userId = new Uuid();
        $this->users = new readonly class(self::EMAIL, $this->userId) implements UserIdentifierResolverInterface {
            public function __construct(private string $email, private Uuid $id)
            {
            }

            public function userId(string $identifier): Uuid
            {
                if ($identifier === $this->email || $identifier === $this->id->toString()) {
                    return $this->id;
                }

                throw new NotFoundException(sprintf('User "%s" not found.', $identifier));
            }
        };
        $this->access = new class implements LibraryAccessPortInterface {
            /** @var array<string, true> */
            public array $memberships = [];

            public function grant(Uuid $userId, Uuid $libraryId): void
            {
                $this->memberships[$userId->toString() . '/' . $libraryId->toString()] = true;
            }

            public function revoke(Uuid $userId, Uuid $libraryId): void
            {
                unset($this->memberships[$userId->toString() . '/' . $libraryId->toString()]);
            }

            public function getUserLibraryIds(Uuid $userId): array
            {
                $ids = [];
                foreach (array_keys($this->memberships) as $membership) {
                    [$user, $library] = explode('/', $membership);
                    if ($user === $userId->toString()) {
                        $ids[] = $library;
                    }
                }

                return $ids;
            }

            public function hasAccess(Uuid $userId, Uuid $libraryId): bool
            {
                return isset($this->memberships[$userId->toString() . '/' . $libraryId->toString()]);
            }
        };
    }

    public function testListShowsEveryLibraryInDisplayOrderWithTheUsersAccess(): void
    {
        $this->access->grant($this->userId, $this->films->getId());

        $entries = $this->list(self::EMAIL);

        self::assertSame(
            [['music', false], ['films', true]],
            array_map(static fn (LibraryAccess $entry): array => [$entry->library->getSlug()->toString(), $entry->granted], $entries),
        );
    }

    public function testGrantingTwiceLeavesOneMembership(): void
    {
        $first = $this->grant($this->userId->toString(), 'music');
        $second = $this->grant(self::EMAIL, $this->music->getId()->toString());

        self::assertTrue($first->granted);
        self::assertTrue($second->granted);
        self::assertSame($this->music, $second->library);
        self::assertSame([$this->music->getId()->toString()], $this->access->getUserLibraryIds($this->userId));
    }

    public function testRevokingAccessTheUserLacksSucceeds(): void
    {
        $this->access->grant($this->userId, $this->films->getId());

        $entry = $this->revoke(self::EMAIL, 'music');

        self::assertFalse($entry->granted);
        self::assertSame($this->music, $entry->library);
        self::assertSame([$this->films->getId()->toString()], $this->access->getUserLibraryIds($this->userId));
    }

    public function testRevokeRemovesTheMembership(): void
    {
        $this->access->grant($this->userId, $this->music->getId());

        self::assertFalse($this->revoke(self::EMAIL, 'music')->granted);
        self::assertSame([], $this->access->getUserLibraryIds($this->userId));
    }

    public function testAnUnknownUserIsNotFoundAndChangesNothing(): void
    {
        foreach ([
            fn () => $this->list('nobody@baander.app'),
            fn () => $this->grant('nobody@baander.app', 'music'),
            fn () => $this->revoke('nobody@baander.app', 'music'),
        ] as $call) {
            try {
                $call();
                self::fail('An unknown user must be not found.');
            } catch (NotFoundException $exception) {
                self::assertSame('User "nobody@baander.app" not found.', $exception->getMessage());
            }
        }

        self::assertSame([], $this->access->memberships);
    }

    public function testAnUnknownLibraryIsNotFoundAndChangesNothing(): void
    {
        $this->access->grant($this->userId, $this->music->getId());
        $unknown = (new Uuid())->toString();

        foreach ([
            fn () => $this->grant(self::EMAIL, $unknown),
            fn () => $this->revoke(self::EMAIL, $unknown),
        ] as $call) {
            try {
                $call();
                self::fail('An unknown library must be not found.');
            } catch (LibraryNotFoundException $exception) {
                self::assertSame(sprintf('Library "%s" not found.', $unknown), $exception->getMessage());
            }
        }

        self::assertSame([$this->music->getId()->toString()], $this->access->getUserLibraryIds($this->userId));
    }

    /** @return list<LibraryAccess> */
    private function list(string $user): array
    {
        return (new ListLibraryAccessHandler($this->users, $this->libraries, $this->access))(new ListLibraryAccessQuery($user));
    }

    private function grant(string $user, string $library): LibraryAccess
    {
        return (new GrantLibraryAccessHandler($this->users, new LibraryLookup($this->libraries), $this->access))(new GrantLibraryAccessCommand($user, $library));
    }

    private function revoke(string $user, string $library): LibraryAccess
    {
        return (new RevokeLibraryAccessHandler($this->users, new LibraryLookup($this->libraries), $this->access))(new RevokeLibraryAccessCommand($user, $library));
    }
}
