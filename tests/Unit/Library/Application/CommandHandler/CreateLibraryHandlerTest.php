<?php

declare(strict_types=1);

namespace App\Tests\Unit\Library\Application\CommandHandler;

use App\Library\Application\Command\CreateLibraryCommand;
use App\Library\Application\CommandHandler\CreateLibraryHandler;
use App\Library\Application\Exception\LibraryRootOverlapsException;
use App\Library\Application\Exception\LibrarySlugTakenException;
use App\Library\Application\Port\LibraryAccessPortInterface;
use App\Library\Domain\Model\Library;
use App\Library\Domain\Repository\LibraryRepositoryInterface;
use App\Library\Domain\ValueObject\LibraryPath;
use App\Library\Domain\ValueObject\LibrarySlug;
use App\Library\Domain\ValueObject\LibraryType;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\FilesystemType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class CreateLibraryHandlerTest extends TestCase
{
    /** @var list<string> */
    private array $timeline = [];

    public function testCreatesTheLibraryAndGrantsTheGranteeInOneTransaction(): void
    {
        $grantee = Uuid::v7();
        $libraries = $this->createStub(LibraryRepositoryInterface::class);
        $libraries->method('findBySlug')->willReturn(null);
        $libraries->method('save')->willReturnCallback(function (): void {
            $this->timeline[] = 'save';
        });
        $access = $this->createMock(LibraryAccessPortInterface::class);
        $access->expects($this->once())->method('grant')
            ->with($grantee, self::isInstanceOf(Uuid::class))
            ->willReturnCallback(function (): void {
                $this->timeline[] = 'grant';
            });

        $library = $this->handler($libraries, $access)(new CreateLibraryCommand(
            name: 'My Music',
            path: '/media/music/',
            type: 'music',
            sortOrder: 2,
            grantTo: $grantee,
        ));

        self::assertSame(['begin', 'save', 'grant', 'commit'], $this->timeline);
        self::assertSame('my-music', $library->getSlug()->toString());
        self::assertSame('/media/music', $library->getPath()->toString());
        self::assertSame(LibraryType::Music, $library->getType());
        self::assertSame(FilesystemType::Local, $library->getFilesystemType());
        self::assertSame(2, $library->getSortOrder());
    }

    public function testGrantsNobodyWithoutAGrantee(): void
    {
        $libraries = $this->createStub(LibraryRepositoryInterface::class);
        $libraries->method('findBySlug')->willReturn(null);
        $access = $this->createMock(LibraryAccessPortInterface::class);
        $access->expects($this->never())->method('grant');

        $this->handler($libraries, $access)(new CreateLibraryCommand(name: 'Console', path: '/media/console', type: 'movie'));
    }

    public function testRefusesATakenSlugWithAConflict(): void
    {
        $libraries = $this->createMock(LibraryRepositoryInterface::class);
        $libraries->method('findBySlug')->willReturn(
            Library::create('Taken', new LibrarySlug('taken'), new LibraryPath('/media/taken'), LibraryType::Music, FilesystemType::Local),
        );
        $libraries->expects($this->never())->method('save');

        $this->expectException(LibrarySlugTakenException::class);
        $this->handler($libraries, $this->createStub(LibraryAccessPortInterface::class))(
            new CreateLibraryCommand(name: 'Other', path: '/media/other', type: 'music', slug: 'taken'),
        );
    }

    #[DataProvider('overlappingRoots')]
    public function testRefusesARootInsideOrContainingAnotherLibrarysRoot(string $existing, string $path): void
    {
        $libraries = $this->createMock(LibraryRepositoryInterface::class);
        $libraries->method('findAllOrdered')->willReturn([$this->existing('music', $existing)]);
        $libraries->expects($this->never())->method('save');

        try {
            $this->handler($libraries, $this->createStub(LibraryAccessPortInterface::class))(
                new CreateLibraryCommand(name: 'Rock', path: $path, type: 'music', slug: 'rock'),
            );
            self::fail('The overlapping root was accepted.');
        } catch (LibraryRootOverlapsException $exception) {
            self::assertSame(['reason' => 'root_overlaps', 'library' => 'music'], $exception->details);
        }
    }

    /** @return array<string, array{string, string}> roots that do not exist, so they compare as written */
    public static function overlappingRoots(): array
    {
        return [
            'inside' => ['/media/music', '/media/music/rock'],
            'containing' => ['/media/music/rock', '/media/music'],
            'identical' => ['/media/music', '/media/music/'],
        ];
    }

    public function testAcceptsASiblingWhoseNameStartsWithAnotherRoot(): void
    {
        $libraries = $this->createMock(LibraryRepositoryInterface::class);
        $libraries->method('findAllOrdered')->willReturn([$this->existing('music', '/media/music')]);
        $libraries->expects($this->once())->method('save');

        $library = $this->handler($libraries, $this->createStub(LibraryAccessPortInterface::class))(
            new CreateLibraryCommand(name: 'Music 2', path: '/media/music2', type: 'music'),
        );

        self::assertSame('/media/music2', $library->getPath()->toString());
    }

    public function testComparesTheResolvedRootsWhenTheyExist(): void
    {
        $base = sys_get_temp_dir() . '/baander-create-library-' . bin2hex(random_bytes(4));
        $filesystem = new Filesystem();
        $filesystem->mkdir($base . '/disk/music/rock');
        $filesystem->symlink($base . '/disk/music', $base . '/music');
        $libraries = $this->createMock(LibraryRepositoryInterface::class);
        $libraries->method('findAllOrdered')->willReturn([$this->existing('music', $base . '/music')]);
        $libraries->expects($this->never())->method('save');

        try {
            $this->expectException(LibraryRootOverlapsException::class);
            $this->handler($libraries, $this->createStub(LibraryAccessPortInterface::class))(
                new CreateLibraryCommand(name: 'Rock', path: $base . '/disk/music/rock', type: 'music'),
            );
        } finally {
            $filesystem->remove($base);
        }
    }

    public function testReportsATakenSlugBeforeAnOverlappingRoot(): void
    {
        $taken = $this->existing('taken', '/media/taken');
        $libraries = $this->createMock(LibraryRepositoryInterface::class);
        $libraries->method('findBySlug')->willReturn($taken);
        $libraries->method('findAllOrdered')->willReturn([$taken]);
        $libraries->expects($this->never())->method('save');

        $this->expectException(LibrarySlugTakenException::class);
        $this->handler($libraries, $this->createStub(LibraryAccessPortInterface::class))(
            new CreateLibraryCommand(name: 'Taken', path: '/media/taken', type: 'music', slug: 'taken'),
        );
    }

    #[DataProvider('invalidCommands')]
    public function testRejectsInvalidInput(CreateLibraryCommand $command, string $message): void
    {
        $libraries = $this->createMock(LibraryRepositoryInterface::class);
        $libraries->expects($this->never())->method('save');

        $this->expectException(InvalidInputException::class);
        $this->expectExceptionMessage($message);
        $this->handler($libraries, $this->createStub(LibraryAccessPortInterface::class))($command);
    }

    /** @return array<string, array{CreateLibraryCommand, string}> */
    public static function invalidCommands(): array
    {
        return [
            'unknown type' => [new CreateLibraryCommand('Music', '/media/music', 'invalid_type'), 'Invalid library type "invalid_type"'],
            'unknown filesystem' => [new CreateLibraryCommand('Music', '/media/music', 'music', 'ftp'), 'Invalid filesystem type "ftp"'],
            'relative path' => [new CreateLibraryCommand('Music', 'media/music', 'music'), 'Library path must be absolute.'],
            'malformed slug' => [new CreateLibraryCommand('Music', '/media/music', 'music', slug: 'Not A Slug!'), 'must contain only lowercase letters'],
            'blank name' => [new CreateLibraryCommand(' ', '/media/music', 'music', slug: 'blank'), 'Library name cannot be empty.'],
        ];
    }

    private function existing(string $slug, string $root): Library
    {
        return Library::create(ucfirst($slug), new LibrarySlug($slug), new LibraryPath($root), LibraryType::Music, FilesystemType::Local);
    }

    private function handler(LibraryRepositoryInterface $libraries, LibraryAccessPortInterface $access): CreateLibraryHandler
    {
        $record = function (string $step): void {
            $this->timeline[] = $step;
        };
        $transaction = new class ($record) implements TransactionPortInterface {
            /** @param \Closure(string): void $record */
            public function __construct(private \Closure $record)
            {
            }

            public function run(callable $operation): mixed
            {
                ($this->record)('begin');
                $result = $operation();
                ($this->record)('commit');

                return $result;
            }
        };

        return new CreateLibraryHandler($libraries, $access, $transaction);
    }
}
