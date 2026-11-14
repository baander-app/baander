<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Interface\Console;

use App\Catalog\Application\Command\MusicBrainzMatch\ApplyMatchCommand;
use App\Catalog\Application\Command\MusicBrainzMatch\MatchApplyResult;
use App\Catalog\Application\Command\MusicBrainzMatch\SeenCredit;
use App\Catalog\Application\Command\MusicBrainzMatch\SeenCredits;
use App\Catalog\Application\Command\MusicBrainzMatch\SeenField;
use App\Catalog\Application\Query\MusicBrainzMatch\CompareMatchQuery;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchField;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchItemType;
use App\Catalog\Application\QueryHandler\MusicBrainzMatch\CompareMatchHandler;
use App\Catalog\Application\QueryHandler\MusicBrainzMatch\MatchComparisonBuilder;
use App\Catalog\Domain\Model\Album;
use App\Catalog\Domain\Model\Artist;
use App\Catalog\Domain\ValueObject\ArtistRole;
use App\Catalog\Interface\Console\MusicBrainzMatchApplyCommand;
use App\Catalog\Interface\Controller\MusicBrainzMatchController;
use App\Catalog\Interface\Request\ApplyMatchRequest;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzRelease;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Interface\Console\AdminCommandSupport;
use App\Tests\Unit\Catalog\Application\QueryHandler\MusicBrainzMatch\MatchQueryFixtures;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;

/**
 * app:metadata:match:apply dispatches the apply the apply route dispatches for the same selection,
 * prints the route's data with --json, applies the comparison's default ticks when no selection
 * is given, and asks before it writes.
 */
final class MusicBrainzMatchApplyCommandTest extends TestCase
{
    use MatchQueryFixtures;

    /** @var list<ApplyMatchCommand> */
    private array $applied = [];

    private ?\Throwable $applyFailure = null;

    protected function setUp(): void
    {
        $this->setUpLibrary();
    }

    /** The web dialog sends the comparison's default ticks; the command without --selection applies the same. */
    public function testTheDefaultTicksApplyTheSameAsTheRouteGivenTheComparisonsTickedRows(): void
    {
        [$album, $release] = $this->longBed();
        $publicId = $album->getPublicId()->toString();
        $comparison = $this->controller()->comparison('album', $publicId, Request::create('/', 'GET', ['candidate' => $release->id]))->getContent()
            |> (static fn (string|false $json): array => json_decode((string) $json, true, flags: JSON_THROW_ON_ERROR)['data']);

        $endpoint = $this->controller()->apply('album', $publicId, ApplyMatchRequest::withDefaultTicks($comparison))->getContent()
            |> (static fn (string|false $json): array => json_decode((string) $json, true, flags: JSON_THROW_ON_ERROR)['data']);
        $apply = $this->apply();
        self::assertSame(Command::SUCCESS, $apply->execute(['type' => 'album', 'public-id' => $publicId, 'candidate' => $release->id, '--force' => true, '--json' => true]), $apply->getDisplay());

        self::assertSame($endpoint, json_decode($apply->getDisplay(), true, flags: JSON_THROW_ON_ERROR));
        [$byRoute, $byCommand] = $this->applied;
        self::assertEquals($byRoute, $byCommand);
        self::assertSame(MatchItemType::Album, $byCommand->type);
        self::assertSame($release->id, $byCommand->candidateId);
        $selection = $byCommand->selection;
        self::assertNotNull($selection);
        $duet = $this->songs[1]->getPublicId()->toString();
        self::assertContainsEquals(new SeenField(MatchField::ReleaseMbid, null), $selection->fields);
        self::assertContainsEquals(new SeenField(MatchField::Mbid, null, $duet), $selection->fields);
        self::assertNotContainsEquals(new SeenField(MatchField::Year, 2023), $selection->fields, 'A year that holds another value is not ticked by default.');
        self::assertContainsEquals(
            new SeenCredits($duet, [new SeenCredit($this->duetArtist()->getPublicId()->toString(), 'primary', 'Post Malone, Luke Combs')]),
            $selection->credits,
        );
        self::assertSame([$this->duetArtist()->getPublicId()->toString()], $selection->artistActions);
        self::assertSame([], $selection->keptArtists);
        self::assertSame([
            'type' => 'album',
            'publicId' => $publicId,
            'candidateId' => $release->id,
            'fields' => ['releaseMbid', 'mbid'],
            'creditsChanged' => true,
            'songsChanged' => 3,
            'mergedArtists' => [$this->duetArtist()->getPublicId()->toString()],
            'removedArtists' => [],
        ], $endpoint);
    }

    public function testASelectionAppliesWhatTheApiBodyNamesAndThePlainOutputSaysWhatWasWritten(): void
    {
        [$album, $release] = $this->longBed();
        $publicId = $album->getPublicId()->toString();
        $duet = $this->songs[1]->getPublicId()->toString();
        $body = [
            'fields' => [['key' => 'year', 'current' => 2023], ['key' => $duet . ':title', 'current' => 'Track 1-2']],
            'credits' => [['key' => $duet . ':credits', 'current' => [['artistPublicId' => $this->duetArtist()->getPublicId()->toString(), 'role' => 'primary', 'creditedName' => 'Post Malone, Luke Combs']]]],
            'artistActions' => [],
            'keptArtists' => [$this->duetArtist()->getPublicId()->toString()],
        ];

        $this->controller()->apply('album', $publicId, new ApplyMatchRequest($release->id, ...$body));
        $apply = $this->apply();
        self::assertSame(Command::SUCCESS, $apply->execute(['type' => 'album', 'public-id' => $publicId, 'candidate' => $release->id, '--selection' => json_encode($body, JSON_THROW_ON_ERROR), '--force' => true]));

        [$byRoute, $byCommand] = $this->applied;
        self::assertEquals($byRoute, $byCommand);
        self::assertEquals([new SeenField(MatchField::Year, 2023), new SeenField(MatchField::Title, 'Track 1-2', $duet)], $byCommand->selection?->fields);
        $display = $apply->getDisplay();
        self::assertStringContainsString(sprintf('MusicBrainz release %s is applied to album %s.', $release->id, $publicId), $display |> self::oneLine(...));
        self::assertMatchesRegularExpression('/Fields written\s+releaseMbid, mbid/', $display);
        self::assertMatchesRegularExpression('/Artists merged\s+' . preg_quote($this->duetArtist()->getPublicId()->toString(), '/') . '/', $display);
        self::assertStringNotContainsString('compared with MusicBrainz', $display, 'A selection applies without comparing first.');
    }

    public function testWithoutASelectionTheCommandPrintsTheComparisonItApplies(): void
    {
        [$album, $release] = $this->longBed();
        $apply = $this->apply();

        self::assertSame(Command::SUCCESS, $apply->execute(['type' => 'album', 'public-id' => $album->getPublicId()->toString(), 'candidate' => $release->id, '--force' => true]));

        self::assertMatchesRegularExpression('/releaseMbid\s+-\s+' . $release->id . '\s+yes/', $apply->getDisplay());
        self::assertCount(1, $this->applied);
    }

    public function testWithoutATerminalOrForceNothingIsApplied(): void
    {
        [$album, $release] = $this->longBed();
        $apply = $this->apply();

        self::assertSame(Command::INVALID, $apply->execute(['type' => 'album', 'public-id' => $album->getPublicId()->toString(), 'candidate' => $release->id, '--json' => true], ['interactive' => false]));

        self::assertSame([], $this->applied);
        self::assertStringContainsString('--force', $apply->getDisplay());
    }

    public function testAMalformedSelectionIsInvalidOnBothPathsWithTheSameMessages(): void
    {
        [$album, $release] = $this->longBed();
        $publicId = $album->getPublicId()->toString();
        $body = ['fields' => [['key' => 'colour', 'current' => 'red'], ['key' => 'year']], 'credits' => [], 'artistActions' => [7], 'keptArtists' => []];

        try {
            $this->controller()->apply('album', $publicId, new ApplyMatchRequest($release->id, ...$body));
            self::fail('The route accepted a malformed selection.');
        } catch (InvalidInputException $invalid) {
            self::assertSame('The match selection is invalid.', $invalid->getMessage());
            self::assertSame([
                'fields[0].key' => ['"colour" names no field row.'],
                'fields[1]' => ['A field row is an object with a key and its current value.'],
                'artistActions[0]' => ['An artist is named by its public ID.'],
            ], $invalid->details);
        }
        $apply = $this->apply();
        self::assertSame(Command::INVALID, $apply->execute(['type' => 'album', 'public-id' => $publicId, 'candidate' => $release->id, '--selection' => json_encode($body, JSON_THROW_ON_ERROR), '--force' => true]));

        $display = $apply->getDisplay() |> self::oneLine(...);
        self::assertStringContainsString('The match selection is invalid.', $display);
        self::assertStringContainsString('fields[0].key: "colour" names no field row.', $display);
        self::assertSame([], $this->applied);
    }

    public function testAStaleApplyExitsOneWithTheConflictsMessage(): void
    {
        [$album, $release] = $this->longBed();
        $this->applyFailure = new ConflictException('The year changed since the comparison. Compare the match again.', ['reason' => 'match_stale']);
        $apply = $this->apply();

        self::assertSame(Command::FAILURE, $apply->execute(['type' => 'album', 'public-id' => $album->getPublicId()->toString(), 'candidate' => $release->id, '--selection' => '{}', '--force' => true]));

        self::assertStringContainsString('The year changed since the comparison.', $apply->getDisplay());
    }

    /** The output on one line, as SymfonyStyle wraps long messages. */
    private static function oneLine(string $display): string
    {
        return (string) preg_replace('/\s+/', ' ', $display);
    }

    /** @return array{Album, MusicBrainzRelease} */
    private function longBed(): array
    {
        $album = $this->addAlbum(year: 2023);
        $release = self::release(self::releaseId(1), [3], ['1-2' => ['artistCredit' => self::credit(self::ALBUM_ARTIST, 'Luke Combs')]]);
        $this->musicBrainz->releases[$release->id] = $release;
        $postMalone = $this->catalog->artist(self::ALBUM_ARTIST);
        $duetArtist = $this->catalog->artist('Post Malone, Luke Combs');
        $this->catalog->creditAlbum($album, $postMalone, ArtistRole::Primary);
        foreach ($this->addSongsOf($album, $release) as $song) {
            $this->catalog->creditSong($song, $song->getTrack() === 2 ? $duetArtist : $postMalone, ArtistRole::Primary);
        }

        return [$album, $release];
    }

    private function duetArtist(): Artist
    {
        return array_find($this->catalog->artists, static fn (Artist $artist): bool => $artist->getName() === 'Post Malone, Luke Combs')
            ?? throw new \LogicException('The duet artist is stored.');
    }

    private function controller(): MusicBrainzMatchController
    {
        return new MusicBrainzMatchController($this->bus());
    }

    private function apply(): CommandTester
    {
        return new CommandTester(new MusicBrainzMatchApplyCommand(new AdminCommandSupport($this->bus())));
    }

    /** The comparison runs for real; the apply records what it was asked and answers as an apply of the duet's merge would. */
    private function bus(): MessageBus
    {
        return new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            CompareMatchQuery::class => [new CompareMatchHandler(
                $this->musicBrainz,
                $this->scorer(),
                $this->snapshots(),
                new MatchComparisonBuilder($this->catalog, $this->artistPort()),
            )],
            ApplyMatchCommand::class => [function (ApplyMatchCommand $command): MatchApplyResult {
                $this->applied[] = $command;
                if ($this->applyFailure !== null) {
                    throw $this->applyFailure;
                }

                return MatchApplyResult::applied(
                    $command->type,
                    $command->publicId ?? '',
                    $command->candidateId,
                    ['releaseMbid', 'mbid'],
                    true,
                    3,
                    [$this->duetArtist()->getPublicId()->toString()],
                    [],
                );
            }],
        ]))]);
    }
}
