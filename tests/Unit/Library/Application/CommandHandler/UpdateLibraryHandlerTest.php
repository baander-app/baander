<?php

declare(strict_types=1);

namespace App\Tests\Unit\Library\Application\CommandHandler;

use App\Library\Application\Command\UpdateLibraryCommand;
use App\Library\Application\CommandHandler\UpdateLibraryHandler;
use App\Library\Application\Service\LibraryLookup;
use App\Library\Domain\Model\Library;
use App\Library\Domain\ValueObject\LibraryPath;
use App\Library\Domain\ValueObject\LibrarySlug;
use App\Library\Domain\ValueObject\LibraryType;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Domain\ValueObject\FilesystemType;
use App\Tests\Fixtures\Library\InMemoryLibraryRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/** The admin panel and `app:library:update` rename and reorder through one handler. */
final class UpdateLibraryHandlerTest extends TestCase
{
    public function testABlankNameIsInvalidInputAndChangesNothing(): void
    {
        $libraries = new InMemoryLibraryRepository(new MockClock());
        $library = $libraries->add(Library::create('Music', new LibrarySlug('music'), new LibraryPath('/media/music'), LibraryType::Music, FilesystemType::Local));
        $handler = new UpdateLibraryHandler(new LibraryLookup($libraries), $libraries);

        try {
            $handler(new UpdateLibraryCommand(library: 'music', name: ' ', sortOrder: 3));
            self::fail('A blank name must be rejected.');
        } catch (InvalidInputException $exception) {
            self::assertSame('Library name cannot be empty.', $exception->getMessage());
        }

        self::assertSame('Music', $library->getName());
        self::assertSame(0, $library->getSortOrder());
    }

    public function testRenamesAndReorders(): void
    {
        $libraries = new InMemoryLibraryRepository(new MockClock());
        $libraries->add(Library::create('Music', new LibrarySlug('music'), new LibraryPath('/media/music'), LibraryType::Music, FilesystemType::Local));

        $updated = (new UpdateLibraryHandler(new LibraryLookup($libraries), $libraries))(new UpdateLibraryCommand(library: 'music', name: 'Records', sortOrder: 3));

        self::assertSame('Records', $updated->getName());
        self::assertSame(3, $updated->getSortOrder());
    }
}
