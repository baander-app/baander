<?php

declare(strict_types=1);

namespace App\Tests\Unit\Library\Domain\Event;

use App\Library\Domain\Event\LibraryDirectoryIndexed;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LibraryDirectoryIndexedTest extends TestCase
{
    public function testPayloadRoundTripPreservesEveryFieldAndOccurrenceTime(): void
    {
        $occurredAt = new DateTimeImmutable('2026-10-21T13:14:15.123456+02:00');
        $files = [self::file(), [...self::file(), 'absolute_path' => '/media/album/two.flac', 'relative_path' => 'album/two.flac']];
        $event = new LibraryDirectoryIndexed('0199fba0-7c00-7000-8000-000000000001', 'music', '/media/album', $files, $occurredAt);

        $restored = LibraryDirectoryIndexed::fromPayload($event->toPayload());

        self::assertSame('library.directory_indexed', $restored->eventName());
        self::assertSame($event->libraryId, $restored->libraryId);
        self::assertSame($event->libraryType, $restored->libraryType);
        self::assertSame($event->directory, $restored->directory);
        self::assertSame($files, $restored->files);
        self::assertEquals($occurredAt, $restored->occurredAt());
        self::assertSame($event->toPayload(), $restored->toPayload());
    }

    /** @param array<string, mixed> $changes */
    #[DataProvider('malformedPayloads')]
    public function testMalformedPayloadFailsVisibly(array $changes, ?string $missingField = null): void
    {
        $payload = array_replace([
            'library_id' => '0199fba0-7c00-7000-8000-000000000001',
            'library_type' => 'music',
            'directory' => '/media/album',
            'files' => [self::file()],
            'occurred_at' => '2026-10-21T13:14:15.123456+02:00',
        ], $changes);
        if ($missingField !== null) {
            unset($payload[$missingField]);
        }

        $this->expectException(InvalidArgumentException::class);
        LibraryDirectoryIndexed::fromPayload($payload);
    }

    /** @return iterable<string, array{array<string, mixed>, ?string}> */
    public static function malformedPayloads(): iterable
    {
        foreach (['library_id', 'library_type', 'directory', 'files', 'occurred_at'] as $field) {
            yield 'missing '.$field => [[], $field];
        }
        yield 'non-string library' => [['library_id' => 12], null];
        yield 'non-string type' => [['library_type' => false], null];
        yield 'non-string directory' => [['directory' => null], null];
        yield 'non-list files' => [['files' => ['first' => self::file()]], null];
        yield 'non-array file' => [['files' => ['one.flac']], null];
        yield 'missing file field' => [['files' => [['absolute_path' => '/media/album/one.flac']]], null];
        yield 'coerced size' => [['files' => [[...self::file(), 'size' => '42']]], null];
        yield 'coerced modified time' => [['files' => [[...self::file(), 'modified_at' => 12.5]]], null];
        yield 'non-string hash' => [['files' => [[...self::file(), 'hash' => null]]], null];
        yield 'non-string occurrence time' => [['occurred_at' => 42], null];
        yield 'invalid occurrence time' => [['occurred_at' => 'not a timestamp'], null];
        yield 'relative occurrence time' => [['occurred_at' => 'tomorrow'], null];
    }

    /** @return array{absolute_path: string, relative_path: string, extension: string, size: int, modified_at: int, hash: string} */
    private static function file(): array
    {
        return ['absolute_path' => '/media/album/one.flac', 'relative_path' => 'album/one.flac', 'extension' => 'flac', 'size' => 42, 'modified_at' => 1792584855, 'hash' => 'content-hash'];
    }
}
