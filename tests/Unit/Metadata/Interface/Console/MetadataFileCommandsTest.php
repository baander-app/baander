<?php

declare(strict_types=1);

namespace App\Tests\Unit\Metadata\Interface\Console;

use App\Metadata\Application\Exception\UnreadableMediaFileException;
use App\Metadata\Interface\Console\MetadataFileExtractCommand;
use App\Metadata\Interface\Console\MetadataFileInputReader;
use App\Metadata\Interface\Console\MetadataFileMatchCommand;
use App\Metadata\Interface\Controller\MetadataSyncController;
use App\Metadata\Interface\Request\ExtractMetadataRequest;
use App\Metadata\Interface\Request\MatchMetadataRequest;
use App\Tests\Support\Metadata\TaggedMediaFiles;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Validator\Validation;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * app:metadata:file:extract and app:metadata:file:match read tags through the port the extract and
 * match routes use, print the routes' data with --json, and reject the input the routes reject.
 */
final class MetadataFileCommandsTest extends TestCase
{
    private const array CANDIDATES = [
        ['title' => 'Test Song', 'artist' => 'Other Artist', 'album' => 'Other Album', 'sourceId' => 'partial'],
        ['title' => 'Test Song', 'artist' => 'Test Artist', 'album' => 'Test Album', 'sourceId' => 'exact', 'source' => 'musicbrainz', 'year' => 2026],
    ];

    private TaggedMediaFiles $files;
    private MetadataSyncController $controller;

    protected function setUp(): void
    {
        $this->files = new TaggedMediaFiles();
        $this->controller = new MetadataSyncController(TaggedMediaFiles::reader());
    }

    protected function tearDown(): void
    {
        $this->files->remove();
    }

    public function testExtractJsonIsTheDataTheRouteAnswers(): void
    {
        $path = $this->files->wav('Wave Song', 'Wave Artist');
        $extract = $this->extract();

        self::assertSame(Command::SUCCESS, $extract->execute(['path' => $path, '--json' => true]), $extract->getDisplay());

        self::assertSame(
            $this->controller->extract(new ExtractMetadataRequest($path)) |> self::data(...),
            $extract->getDisplay() |> self::decoded(...),
        );
    }

    public function testExtractPrintsTheFormatAndEachTag(): void
    {
        $extract = $this->extract();

        self::assertSame(Command::SUCCESS, $extract->execute(['path' => $this->files->mp3()]));

        $display = $extract->getDisplay();
        self::assertStringContainsString('Format: mp3', $display);
        self::assertMatchesRegularExpression('/title\s+Test Song/', $display);
        self::assertMatchesRegularExpression('/mbid\s+-/', $display);
    }

    public function testExtractExitsInvalidForAPathWithNoFile(): void
    {
        $extract = $this->extract();

        self::assertSame(Command::INVALID, $extract->execute(['path' => $this->files->missing()]));
        self::assertStringContainsString(UnreadableMediaFileException::missing()->getMessage(), self::oneLine($extract));
    }

    public function testExtractRejectsABlankPathWithTheRequestsMessage(): void
    {
        $extract = $this->extract();

        self::assertSame(Command::INVALID, $extract->execute(['path' => '']));
        self::assertStringContainsString('Validation failed.', $extract->getDisplay());
        self::assertStringContainsString('path: Path is required.', $extract->getDisplay());
    }

    public function testMatchJsonIsTheDataTheRouteAnswers(): void
    {
        $path = $this->files->mp3();
        $match = $this->match();

        self::assertSame(
            Command::SUCCESS,
            $match->execute(['path' => $path, '--candidates' => json_encode(self::CANDIDATES, JSON_THROW_ON_ERROR), '--json' => true]),
            $match->getDisplay(),
        );

        $printed = $match->getDisplay() |> self::decoded(...);
        self::assertSame($this->controller->match(new MatchMetadataRequest($path, self::CANDIDATES)) |> self::data(...), $printed);
        self::assertSame(['exact', 'partial'], array_column($printed, 'sourceId'));
    }

    public function testMatchListsTheCandidatesBestFirst(): void
    {
        $match = $this->match();

        self::assertSame(Command::SUCCESS, $match->execute(['path' => $this->files->mp3(), '--candidates' => json_encode(self::CANDIDATES, JSON_THROW_ON_ERROR)]));

        $display = $match->getDisplay();
        self::assertMatchesRegularExpression('/1\.00\s+musicbrainz\s+exact\s+Test Song\s+Test Artist\s+Test Album\s+2026/', $display);
        self::assertLessThan(strpos($display, 'partial'), strpos($display, 'exact'));
    }

    public function testMatchSaysSoWhenNoCandidateFits(): void
    {
        $match = $this->match();

        self::assertSame(Command::SUCCESS, $match->execute(['path' => $this->files->mp3(), '--candidates' => '[{"title": "XYZ", "artist": "ABC", "album": "DEF"}]']));
        self::assertStringContainsString('No candidate fits the tags of the file.', $match->getDisplay());
    }

    public function testMatchWithoutCandidatesGetsTheRequestsMessages(): void
    {
        $match = $this->match();

        self::assertSame(Command::INVALID, $match->execute(['path' => $this->files->mp3()]));
        self::assertStringContainsString('candidates: Candidates are required.', $match->getDisplay());
        self::assertStringContainsString('candidates: Non-empty "candidates" array is required.', $match->getDisplay());
    }

    public function testMatchRejectsCandidatesThatAreNotAJsonArrayOfObjects(): void
    {
        foreach (['{"title": "Test Song"}', '["Test Song"]', 'not json'] as $candidates) {
            $match = $this->match();

            self::assertSame(Command::INVALID, $match->execute(['path' => $this->files->mp3(), '--candidates' => $candidates]), $candidates);
            self::assertStringContainsString('The --candidates option must be a JSON array of objects.', self::oneLine($match));
        }
    }

    public function testMatchExitsInvalidForAFileNoTagReaderSupports(): void
    {
        $match = $this->match();

        self::assertSame(Command::INVALID, $match->execute(['path' => $this->files->unsupported(), '--candidates' => '[{"title": "Test Song"}]']));
        self::assertStringContainsString(UnreadableMediaFileException::unsupportedFormat()->getMessage(), self::oneLine($match));
    }

    private function extract(): CommandTester
    {
        return new CommandTester(new MetadataFileExtractCommand(TaggedMediaFiles::reader(), $this->inputReader()));
    }

    private function match(): CommandTester
    {
        return new CommandTester(new MetadataFileMatchCommand(TaggedMediaFiles::reader(), $this->inputReader()));
    }

    private function inputReader(): MetadataFileInputReader
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id): string => $id === 'errors.validation.failed' ? 'Validation failed.' : $id,
        );

        return new MetadataFileInputReader(Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator(), $translator);
    }

    private static function data(JsonResponse $response): mixed
    {
        return (string) $response->getContent() |> self::decoded(...) |> (static fn (array $body): mixed => $body['data']);
    }

    /** @return array<array-key, mixed> */
    private static function decoded(string $json): array
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /** The output on one line, as SymfonyStyle wraps long error messages. */
    private static function oneLine(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }
}
