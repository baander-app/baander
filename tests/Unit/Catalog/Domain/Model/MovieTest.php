<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Domain\Model;

use App\Catalog\Domain\Model\Movie;
use App\Shared\Domain\Model\Uuid;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MovieTest extends TestCase
{
    public function testAdminEditsLockEverySuppliedField(): void
    {
        $movie = Movie::create(Uuid::v7(), 'Original', 1995, 'Original summary');

        $movie->edit(title: 'Edited', year: 1996, summary: 'Edited summary');

        self::assertSame('Edited', $movie->getTitle());
        self::assertSame(1996, $movie->getYear());
        self::assertSame('Edited summary', $movie->getSummary());
        self::assertSame(['title', 'year', 'summary'], $movie->getLockedFields());
    }

    public function testLaterAdminEditsCanChangeAnAlreadyLockedField(): void
    {
        $movie = Movie::create(Uuid::v7(), 'Original');
        $movie->edit(title: 'First edit');

        $movie->edit(title: 'Second edit');

        self::assertSame('Second edit', $movie->getTitle());
        self::assertSame(['title'], $movie->getLockedFields());
    }

    public function testAutomaticMetadataKeepsAdminFieldsAndAppliesTheOtherFields(): void
    {
        $movie = Movie::create(Uuid::v7(), 'Original', 1995);
        $movie->edit(title: 'Edited title', summary: 'Edited summary');

        $movie->updateMetadata(title: 'Provider title', year: 1996, summary: 'Provider summary', overview: 'Provider overview');

        self::assertSame('Edited title', $movie->getTitle());
        self::assertSame('Edited summary', $movie->getSummary());
        self::assertSame(1996, $movie->getYear());
        self::assertSame('Provider overview', $movie->getOverview());
    }

    public function testAnInvalidAdminTitleChangesNeitherValuesNorLocks(): void
    {
        $movie = Movie::create(Uuid::v7(), 'Original', 1995);
        try {
            $movie->edit(title: ' ', year: 2000);
            self::fail('An empty movie title must fail.');
        } catch (InvalidArgumentException) {
            self::assertSame('Original', $movie->getTitle());
            self::assertSame(1995, $movie->getYear());
            self::assertSame([], $movie->getLockedFields());
        }
    }

    public function testReconstitutionKeepsFieldLocks(): void
    {
        $movie = Movie::create(Uuid::v7(), 'Original', 1995);
        $movie->edit(year: 1996);
        $restored = Movie::reconstitute(clone $movie->getState());

        $restored->updateMetadata(year: 2000);

        self::assertSame(1996, $restored->getYear());
        self::assertSame(['year'], $restored->getLockedFields());
    }
}
