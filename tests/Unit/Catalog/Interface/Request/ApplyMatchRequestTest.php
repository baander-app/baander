<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Interface\Request;

use App\Catalog\Application\Command\MusicBrainzMatch\MatchSelection;
use App\Catalog\Application\Command\MusicBrainzMatch\SeenCredit;
use App\Catalog\Application\Command\MusicBrainzMatch\SeenCredits;
use App\Catalog\Application\Command\MusicBrainzMatch\SeenField;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchField;
use App\Catalog\Interface\Request\ApplyMatchRequest;
use App\Shared\Application\Exception\InvalidInputException;
use PHPUnit\Framework\TestCase;

/**
 * An apply request names each ticked row by the key the comparison gave it: the field or "credits"
 * for the item's own row, prefixed by "<song public ID>:" for a row of one of an album's songs.
 */
final class ApplyMatchRequestTest extends TestCase
{
    private const string SONG = 'lukeCombsDuetSongId01';
    private const string ARTIST = 'postMaloneLukeCombs01';

    public function testEachKeyComesApartIntoTheSongAndTheRowItNames(): void
    {
        $request = new ApplyMatchRequest(
            candidate: '0e1ea5e0-0000-4000-8000-000000000001',
            fields: [
                ['key' => 'year', 'current' => 2023],
                ['key' => 'genres', 'current' => ['Country', 'Pop']],
                ['key' => 'cover', 'current' => true],
                ['key' => self::SONG . ':title', 'current' => null],
            ],
            credits: [
                ['key' => 'credits', 'current' => []],
                ['key' => self::SONG . ':credits', 'current' => [['artistPublicId' => self::ARTIST, 'role' => 'primary', 'creditedName' => 'Post Malone, Luke Combs']]],
            ],
            artistActions: [self::ARTIST],
            keptArtists: ['headHunterzArtistId01'],
        );

        self::assertEquals(new MatchSelection(
            fields: [
                new SeenField(MatchField::Year, 2023),
                new SeenField(MatchField::Genres, ['Country', 'Pop']),
                new SeenField(MatchField::Cover, true),
                new SeenField(MatchField::Title, null, self::SONG),
            ],
            credits: [
                new SeenCredits(null, []),
                new SeenCredits(self::SONG, [new SeenCredit(self::ARTIST, 'primary', 'Post Malone, Luke Combs')]),
            ],
            artistActions: [self::ARTIST],
            keptArtists: ['headHunterzArtistId01'],
        ), $request->selection());
    }

    public function testEveryMalformedEntryIsNamedByItsPath(): void
    {
        $request = new ApplyMatchRequest(
            candidate: '0e1ea5e0-0000-4000-8000-000000000001',
            fields: [
                ['key' => 'credits', 'current' => null],
                ['key' => ':title', 'current' => 'Track'],
                ['key' => 'year', 'current' => 20.5],
                'year',
            ],
            credits: [
                ['key' => 'year', 'current' => []],
                ['key' => 'credits', 'current' => [['artistPublicId' => self::ARTIST, 'role' => 3, 'creditedName' => 'X']]],
            ],
            keptArtists: [''],
        );

        $invalid = $this->invalid(static fn (): MatchSelection => $request->selection());

        self::assertSame('The match selection is invalid.', $invalid->getMessage());
        self::assertSame([
            'fields[0].key' => ['"credits" names no field row.'],
            'fields[1].key' => ['":title" names no field row.'],
            'fields[2].current' => ['A current value is a text, a number, true, a list of texts or null.'],
            'fields[3]' => ['A field row is an object with a key and its current value.'],
            'credits[0].key' => ['"year" names no credits row.'],
            'credits[1].current[0]' => ['A credit is an object with the artist\'s public ID, its role or null, and the credited name.'],
            'keptArtists[0]' => ['An artist is named by its public ID.'],
        ], $invalid->details);
    }

    public function testASelectionObjectNeedsListsAndIgnoresOtherMembers(): void
    {
        $request = ApplyMatchRequest::fromSelection('0e1ea5e0-0000-4000-8000-000000000001', ['candidate' => 'ignored', 'fields' => [['key' => 'year', 'current' => 2023]]]);
        self::assertSame('0e1ea5e0-0000-4000-8000-000000000001', $request->candidate);
        self::assertEquals([new SeenField(MatchField::Year, 2023)], $request->selection()->fields);

        $invalid = $this->invalid(static fn (): ApplyMatchRequest => ApplyMatchRequest::fromSelection('x', ['fields' => ['key' => 'year'], 'keptArtists' => 'abc']));
        self::assertSame(['fields' => ['This value should be a list.'], 'keptArtists' => ['This value should be a list.']], $invalid->details);
    }

    /** @param \Closure(): mixed $call */
    private function invalid(\Closure $call): InvalidInputException
    {
        try {
            $call();
        } catch (InvalidInputException $invalid) {
            return $invalid;
        }

        self::fail('The request was accepted.');
    }
}
