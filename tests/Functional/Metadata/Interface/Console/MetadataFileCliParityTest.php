<?php

declare(strict_types=1);

namespace App\Tests\Functional\Metadata\Interface\Console;

use App\Auth\Domain\Model\User;
use App\Tests\Functional\TestCase;
use App\Tests\Support\Metadata\TaggedMediaFiles;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Response;

/**
 * app:metadata:file:extract and app:metadata:file:match read the tags the extract and match routes
 * read, print the routes' data with --json, and reject the input the routes reject.
 */
final class MetadataFileCliParityTest extends TestCase
{
    private const array CANDIDATES = [
        ['title' => 'Test Song', 'artist' => 'Other Artist', 'album' => 'Other Album', 'sourceId' => 'partial'],
        ['title' => 'Test Song', 'artist' => 'Test Artist', 'album' => 'Test Album', 'sourceId' => 'exact', 'source' => 'musicbrainz'],
    ];

    private TaggedMediaFiles $files;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->files = new TaggedMediaFiles();
        $this->admin = $this->createAdminUser();
    }

    protected function tearDown(): void
    {
        $this->files->remove();

        parent::tearDown();
    }

    public function testExtractPrintsTheApiData(): void
    {
        $path = $this->files->wav('Wave Song', 'Wave Artist');
        $api = $this->assertJsonResponse($this->extractRequest($path), 200, 'data')['data'];
        self::assertSame('Wave Song', $api['metadata']['title']);

        $extract = $this->command('app:metadata:file:extract');
        self::assertSame(Command::SUCCESS, $extract->execute(['path' => $path, '--json' => true]), $extract->getDisplay());
        self::assertSame($api, $this->json($extract));
    }

    public function testMatchPrintsTheApiData(): void
    {
        $path = $this->files->mp3();
        $api = $this->assertJsonResponse(
            $this->authenticatedRequest('POST', '/api/metadata/match', $this->admin, ['path' => $path, 'candidates' => self::CANDIDATES]),
            200,
            'data',
        )['data'];
        self::assertSame(['exact', 'partial'], array_column($api, 'sourceId'));

        $match = $this->command('app:metadata:file:match');
        self::assertSame(
            Command::SUCCESS,
            $match->execute(['path' => $path, '--candidates' => json_encode(self::CANDIDATES, JSON_THROW_ON_ERROR), '--json' => true]),
            $match->getDisplay(),
        );
        self::assertSame($api, $this->json($match));
    }

    public function testAPathWithNoFileAnswers422AndExits2WithTheSameMessage(): void
    {
        $missing = $this->files->missing();
        $error = $this->assertJsonResponse($this->extractRequest($missing), 422, 'error')['error'];

        $extract = $this->command('app:metadata:file:extract');
        self::assertSame(Command::INVALID, $extract->execute(['path' => $missing], ['capture_stderr_separately' => true]));
        self::assertStringContainsString((string) $error['message'], $extract->getErrorOutput() |> $this->flatten(...));

        $match = $this->command('app:metadata:file:match');
        self::assertSame(Command::INVALID, $match->execute(['path' => $missing, '--candidates' => '[{"title": "Test Song"}]']));
        $this->assertJsonResponse(
            $this->authenticatedRequest('POST', '/api/metadata/match', $this->admin, ['path' => $missing, 'candidates' => [['title' => 'Test Song']]]),
            422,
        );
    }

    public function testAFileNoTagReaderSupportsAnswers422AndExits2WithTheSameMessage(): void
    {
        $unsupported = $this->files->unsupported();
        $error = $this->assertJsonResponse($this->extractRequest($unsupported), 422, 'error')['error'];

        $extract = $this->command('app:metadata:file:extract');
        self::assertSame(Command::INVALID, $extract->execute(['path' => $unsupported], ['capture_stderr_separately' => true]));
        self::assertStringContainsString((string) $error['message'], $extract->getErrorOutput() |> $this->flatten(...));
    }

    public function testMatchWithoutCandidatesGetsTheSameMessagesOnBothPaths(): void
    {
        $path = $this->files->mp3();
        $error = $this->assertJsonResponse(
            $this->authenticatedRequest('POST', '/api/metadata/match', $this->admin, ['path' => $path]),
            422,
            'error',
        )['error'];
        self::assertIsArray($error['details']['candidates'] ?? null);
        self::assertContains('Non-empty "candidates" array is required.', $error['details']['candidates']);

        $match = $this->command('app:metadata:file:match');
        self::assertSame(Command::INVALID, $match->execute(['path' => $path], ['capture_stderr_separately' => true]));
        $stderr = $match->getErrorOutput() |> $this->flatten(...);
        self::assertStringContainsString((string) $error['message'], $stderr);
        foreach ($error['details']['candidates'] as $message) {
            self::assertStringContainsString('candidates: ' . $message, $stderr);
        }
    }

    private function extractRequest(string $path): Response
    {
        return $this->authenticatedRequest('POST', '/api/metadata/extract', $this->admin, ['path' => $path]);
    }

    /** @return array<array-key, mixed> */
    private function json(CommandTester $tester): array
    {
        $decoded = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /** The output on one line, as SymfonyStyle wraps long error messages. */
    private function flatten(string $output): string
    {
        return (string) preg_replace('/\s+/', ' ', $output);
    }

    private function command(string $name): CommandTester
    {
        return new CommandTester(new Application($this->client->getKernel())->find($name));
    }
}
