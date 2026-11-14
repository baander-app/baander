<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application\Port;

use App\Catalog\Application\Port\ArtistIdentity;
use PHPUnit\Framework\TestCase;

final class ArtistIdentityTest extends TestCase
{
    private const string ID_X = '9a709693-b4f8-4da9-8cc1-038c911a61be';
    private const string ID_Y = '5b11f4ce-a62d-471e-81fc-a69a8278c7da';

    public function testTheNameLosesNulBytesAndSurroundingWhitespace(): void
    {
        self::assertSame('Bulldogg', (new ArtistIdentity(" Bulldogg\0"))->name);
        self::assertSame('Post Malone', (new ArtistIdentity("Post\0 Malone"))->name);
    }

    public function testABlankNameIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ArtistIdentity(" \0");
    }

    public function testOneValidIdIsKeptInCanonicalForm(): void
    {
        self::assertSame(self::ID_X, (new ArtistIdentity('Headhunterz', [' ' . strtoupper(self::ID_X) . ' ']))->musicbrainzId?->toString());
    }

    public function testTheSameIdInAnotherLetterCaseCountsOnce(): void
    {
        self::assertSame(self::ID_X, (new ArtistIdentity('Headhunterz', [self::ID_X, strtoupper(self::ID_X)]))->musicbrainzId?->toString());
    }

    public function testMalformedAndEmptyIdsAreIgnored(): void
    {
        self::assertNull((new ArtistIdentity('Suspekt', ['not-a-musicbrainz-id']))->musicbrainzId);
        self::assertNull((new ArtistIdentity('Suspekt', ['', '  ']))->musicbrainzId);
        self::assertSame(self::ID_X, (new ArtistIdentity('Suspekt', ['not-a-musicbrainz-id', self::ID_X, '']))->musicbrainzId?->toString());
    }

    public function testSeveralDistinctIdsGiveNoId(): void
    {
        self::assertNull((new ArtistIdentity('A feat. B', [self::ID_X, self::ID_Y]))->musicbrainzId);
    }

    public function testTheKeyIsEqualForIdentitiesThatResolveAlike(): void
    {
        self::assertSame((new ArtistIdentity('Nirvana '))->key(), (new ArtistIdentity('Nirvana', [self::ID_X, self::ID_Y]))->key());
        self::assertSame((new ArtistIdentity('H', [self::ID_X]))->key(), (new ArtistIdentity('H', [strtoupper(self::ID_X), 'bad']))->key());
        self::assertNotSame((new ArtistIdentity('H'))->key(), (new ArtistIdentity('H', [self::ID_X]))->key());
        self::assertNotSame((new ArtistIdentity('Headhunterz', [self::ID_X]))->key(), (new ArtistIdentity('HeadHunterz', [self::ID_X]))->key());
    }
}
