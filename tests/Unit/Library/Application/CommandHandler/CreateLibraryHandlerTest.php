<?php

declare(strict_types=1);

namespace App\Tests\Unit\Library\Application\CommandHandler;

use App\Library\Application\Command\CreateLibraryCommand;
use App\Library\Application\CommandHandler\CreateLibraryHandler;
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
