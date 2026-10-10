<?php

declare(strict_types=1);

namespace App\Tests\Functional\Library;

use App\Auth\Domain\Model\User;
use App\Library\Application\Port\LibraryAccessPortInterface;
use App\Library\Application\Port\LibraryMediaFilesInterface;
use App\Library\Application\Port\LibraryPortInterface;
use App\Library\Application\Query\LibraryMembershipQueryPort;
use App\Library\Domain\Event\LibraryScanCompleted;
use App\Library\Domain\Model\Library;
use App\Library\Domain\ValueObject\LibrarySlug;
use App\Notification\Application\DTO\CreateNotificationCommand;
use App\Notification\Domain\Repository\NotificationRepositoryInterface;
use App\Shared\Application\Http\BaanderHeader;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Functional\TestCase;
use App\UserPreference\Application\Port\UserSettingsContractInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The admin API and the `app:library:*` commands reach the same use cases, only admins
 * change libraries, and a scan claim admits one scan at a time on both paths.
 */
final class LibraryAdministrationTest extends TestCase
{
    /** Holds this test's library roots; never a root itself, as roots may not overlap. */
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/baander-library-admin-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
        parent::tearDown();
    }

    public function testAMemberWithAccessCannotRenameDeleteOrScanALibrary(): void
    {
        $library = $this->createThroughApi('member-' . bin2hex(random_bytes(3)));
        $member = $this->createTestUser();
        $this->service(LibraryAccessPortInterface::class)->grant($member->getId(), Uuid::fromString($library['id']));

        $this->assertJsonResponse($this->authenticatedRequest('PATCH', '/api/libraries/' . $library['id'], $member, ['name' => 'Hijacked']), 403);
        $this->assertJsonResponse($this->authenticatedRequest('DELETE', '/api/libraries/' . $library['id'], $member), 403);
        $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/libraries/' . $library['id'] . '/scan', $member), 403);
        $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/libraries/scan-all', $member), 403);

        $stored = $this->library($library['slug']);
        self::assertSame('Library ' . $library['slug'], $stored->getName());
        self::assertNull($stored->getDiscoveryStatus());
    }

    public function testTheApiAndTheConsoleCreateTheSameLibraryAndRefuseTheSameSlug(): void
    {
        $admin = $this->createAdminUser();
        $suffix = bin2hex(random_bytes(3));
        // Two libraries cannot share a root, so each gets its own.
        [$apiRoot, $cliRoot] = [$this->root(), $this->root()];
        $api = $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/libraries', $admin, [
            'name' => 'Shared Music ' . $suffix,
            'path' => $apiRoot . '/',
            'type' => 'music',
            'sortOrder' => 3,
        ]), 201, 'data')['data'];

        $create = $this->command('app:library:create');
        self::assertSame(Command::SUCCESS, $create->execute([
            'name' => 'Shared Music ' . $suffix,
            'path' => $cliRoot . '/',
            'type' => 'music',
            '--slug' => 'cli-' . $api['slug'],
            '--sort-order' => '3',
        ]), $create->getDisplay());

        $fromApi = $this->library($api['slug']);
        $fromCli = $this->library('cli-' . $api['slug']);
        foreach (['getName', 'getType', 'getFilesystemType', 'getSortOrder', 'getDiscoveryStatus'] as $field) {
            self::assertEquals($fromApi->{$field}(), $fromCli->{$field}(), $field);
        }
        self::assertSame([$apiRoot, $cliRoot], [$fromApi->getPath()->toString(), $fromCli->getPath()->toString()]);

        $duplicate = $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/libraries', $admin, [
            'name' => 'Again', 'path' => $this->root(), 'type' => 'music', 'slug' => $api['slug'],
        ]), 409);
        $again = $this->command('app:library:create');
        self::assertSame(Command::FAILURE, $again->execute([
            'name' => 'Again', 'path' => $this->root(), 'type' => 'music', '--slug' => $api['slug'],
        ]));
        self::assertStringContainsString($duplicate['error']['message'], self::flat($again->getDisplay()));

        $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/libraries', $admin, [
            'name' => 'Bad', 'path' => $this->root(), 'type' => 'invalid_type',
        ]), 422);
        self::assertSame(Command::INVALID, $this->command('app:library:create')->execute([
            'name' => 'Bad', 'path' => $this->root(), 'type' => 'invalid_type',
        ]));
    }

    public function testScanCompletedNotificationsGoToTheWebCreatorAndToNobodyForAConsoleLibrary(): void
    {
        $admin = $this->createAdminUser();
        $api = $this->createThroughApi('notified-' . bin2hex(random_bytes(3)), $admin);
        $create = $this->command('app:library:create');
        $cliSlug = 'console-' . bin2hex(random_bytes(3));
        self::assertSame(Command::SUCCESS, $create->execute(['name' => 'Console', 'path' => $this->root(), 'type' => 'music', '--slug' => $cliSlug]));
        $cli = $this->library($cliSlug);

        $members = $this->service(LibraryMembershipQueryPort::class);
        self::assertSame([$admin->getId()->toString()], $members->findUserIdsForLibrary(Uuid::fromString($api['id'])));
        self::assertSame([], $members->findUserIdsForLibrary($cli->getId()));

        $this->notifyScanCompleted(Uuid::fromString($api['id']));
        $this->notifyScanCompleted($cli->getId());
        self::assertSame(1, $this->service(NotificationRepositoryInterface::class)->countUnread($admin->getId()));
    }

    public function testAClaimedLibraryRefusesASecondScanOnBothPaths(): void
    {
        $admin = $this->createAdminUser();
        $library = $this->createThroughApi('claimed-' . bin2hex(random_bytes(3)), $admin);

        $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/libraries/' . $library['id'] . '/scan', $admin), 202);
        $conflict = $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/libraries/' . $library['id'] . '/scan', $admin), 409);

        $scan = $this->command('app:library:scan');
        self::assertSame(Command::FAILURE, $scan->execute(['library' => $library['slug']]));
        self::assertStringContainsString($conflict['error']['message'], self::flat($scan->getDisplay()));
        self::assertSame('scanning', $this->library($library['slug'])->getDiscoveryStatus());
    }

    public function testAFailedConsoleScanReleasesItsClaimForTheWeb(): void
    {
        $admin = $this->createAdminUser();
        // Discovery does not support podcast libraries, so the inline scan fails after claiming.
        $library = $this->createThroughApi('failing-' . bin2hex(random_bytes(3)), $admin, 'podcast');

        $scan = $this->command('app:library:scan');
        self::assertSame(Command::FAILURE, $scan->execute(['library' => $library['id']]));
        self::assertStringContainsString('Unsupported library type', self::flat($scan->getDisplay()));

        self::assertSame('failed', $this->library($library['slug'])->getDiscoveryStatus());
        $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/libraries/' . $library['id'] . '/scan', $admin), 202);
    }

    public function testALostQueuedScanBlocksNewScansOnlyUntilItsLeaseLapses(): void
    {
        $admin = $this->createAdminUser();
        $library = $this->createThroughApi('lost-' . bin2hex(random_bytes(3)), $admin);
        // The queued scan is lost, as in a server restart: the test transport never runs it.
        $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/libraries/' . $library['id'] . '/scan', $admin), 202);
        $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/libraries/' . $library['id'] . '/scan', $admin), 409);

        $this->lapseClaim($library['id']);

        $shown = $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/libraries/' . $library['id'], $admin), 200, 'data')['data'];
        self::assertSame('failed', $shown['scanStatus'], 'The panel offers a scan again.');
        $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/libraries/' . $library['id'] . '/scan', $admin), 202);
        $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/libraries/' . $library['id'] . '/scan', $admin), 409);
    }

    public function testReleaseRefusesALiveClaimWithoutForceAndClearsAClaimWhoseLeaseLapsed(): void
    {
        $admin = $this->createAdminUser();
        $library = $this->createThroughApi('killed-' . bin2hex(random_bytes(3)), $admin);
        $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/libraries/' . $library['id'] . '/scan', $admin), 202);

        // The claim is live, so its scan may still run: --release alone refuses.
        $refused = $this->command('app:library:scan');
        self::assertSame(Command::FAILURE, $refused->execute(['library' => $library['slug'], '--release' => true]));
        self::assertStringContainsString('--force', self::flat($refused->getDisplay()));
        self::assertSame('scanning', $this->library($library['slug'])->getDiscoveryStatus());

        // A scan whose process died stops renewing its claim, which then lapses.
        $this->lapseClaim($library['id']);
        $release = $this->command('app:library:scan');
        self::assertSame(Command::SUCCESS, $release->execute(['library' => $library['slug'], '--release' => true]), $release->getDisplay());
        self::assertSame('failed', $this->library($library['slug'])->getDiscoveryStatus());

        $again = $this->command('app:library:scan');
        self::assertSame(Command::SUCCESS, $again->execute(['library' => $library['slug'], '--release' => true]));
        self::assertStringContainsString('nothing changed', self::flat($again->getDisplay()));
        $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/libraries/' . $library['id'] . '/scan', $admin), 202);

        // An operator who knows the scan is gone releases a live claim with --force.
        $forced = $this->command('app:library:scan');
        self::assertSame(Command::SUCCESS, $forced->execute(['library' => $library['id'], '--release' => true, '--force' => true]), $forced->getDisplay());
        self::assertSame('failed', $this->library($library['slug'])->getDiscoveryStatus());
        $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/libraries/' . $library['id'] . '/scan', $admin), 202);
    }

    public function testReleaseNamesADeleteClaimAndEndsItWithoutTouchingTheScanStatus(): void
    {
        $admin = $this->createAdminUser();
        $library = $this->createThroughApi('deleting-' . bin2hex(random_bytes(3)), $admin);
        $status = $this->library($library['slug'])->getDiscoveryStatus();
        $this->service(LibraryMediaFilesInterface::class)->claim(Uuid::fromString($library['id']));

        // The delete's claim is live, so it may still be unlinking files: --release alone refuses.
        $refused = $this->command('app:library:scan');
        self::assertSame(Command::FAILURE, $refused->execute(['library' => $library['slug'], '--release' => true]));
        self::assertStringContainsString('A delete with files holds a live claim', self::flat($refused->getDisplay()));
        $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/libraries/' . $library['id'] . '/scan', $admin), 409);

        $this->lapseClaim($library['id']);
        $release = $this->command('app:library:scan');
        self::assertSame(Command::SUCCESS, $release->execute(['library' => $library['slug'], '--release' => true]), $release->getDisplay());
        self::assertStringContainsString('Released the delete claim', self::flat($release->getDisplay()));
        self::assertSame($status, $this->library($library['slug'])->getDiscoveryStatus());
        $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/libraries/' . $library['id'] . '/scan', $admin), 202);
    }

    public function testScanAllSkipsLibrariesAlreadyScanningAndReportsTheOnesItStarted(): void
    {
        $admin = $this->createAdminUser();
        $busy = $this->createThroughApi('busy-' . bin2hex(random_bytes(3)), $admin);
        $idle = $this->createThroughApi('idle-' . bin2hex(random_bytes(3)), $admin);
        $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/libraries/' . $busy['id'] . '/scan', $admin), 202);

        $scan = $this->command('app:library:scan');
        $scan->execute(['--all' => true]);
        $display = $scan->getDisplay();

        self::assertMatchesRegularExpression('/Skipped, already scanning: [^\n]*' . $busy['slug'] . '/', $display);
        self::assertMatchesRegularExpression('/Started: [^\n]*' . $idle['slug'] . '/', $display);
        self::assertDoesNotMatchRegularExpression('/Started: [^\n]*' . $busy['slug'] . '/', $display);
        self::assertSame('completed', $this->library($idle['slug'])->getDiscoveryStatus());
        self::assertSame('scanning', $this->library($busy['slug'])->getDiscoveryStatus());
    }

    public function testListShowsEveryLibraryFromTheShell(): void
    {
        $library = $this->createThroughApi('listed-' . bin2hex(random_bytes(3)));

        $list = $this->command('app:library:list');
        self::assertSame(Command::SUCCESS, $list->execute(['--json' => true]));
        $data = json_decode($list->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertContains($library['slug'], array_column($data, 'slug'));

        $api = $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/libraries', $this->createAdminUser()), 200, 'data');
        self::assertSame($api['data'], $data);
    }

    public function testDeleteWithoutATerminalAndWithoutForceDeletesNothing(): void
    {
        $library = $this->createThroughApi('kept-' . bin2hex(random_bytes(3)));

        $delete = $this->command('app:library:delete');
        self::assertSame(Command::INVALID, $delete->execute(['library' => $library['slug']], ['interactive' => false]));
        self::assertNotNull($this->service(LibraryPortInterface::class)->findBySlug(new LibrarySlug($library['slug'])));

        $forced = $this->command('app:library:delete');
        self::assertSame(Command::SUCCESS, $forced->execute(['library' => $library['id'], '--force' => true], ['interactive' => false]));
        $this->entityManager->clear();
        self::assertNull($this->service(LibraryPortInterface::class)->findBySlug(new LibrarySlug($library['slug'])));
    }

    public function testRenameThroughTheConsoleMatchesTheApi(): void
    {
        $admin = $this->createAdminUser();
        $library = $this->createThroughApi('renamed-' . bin2hex(random_bytes(3)), $admin);

        $update = $this->command('app:library:update');
        self::assertSame(Command::SUCCESS, $update->execute(['library' => $library['slug'], '--name' => 'Renamed', '--sort-order' => '7']), $update->getDisplay());
        $shown = $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/libraries/' . $library['id'], $admin), 200, 'data')['data'];
        self::assertSame('Renamed', $shown['name']);
        self::assertSame(7, $shown['sortOrder']);

        $this->assertJsonResponse($this->authenticatedRequest('PATCH', '/api/libraries/' . $library['id'], $admin, ['name' => ' ']), 422);
        self::assertSame(Command::INVALID, $this->command('app:library:update')->execute(['library' => $library['slug'], '--name' => ' ']));
        self::assertSame(Command::FAILURE, $this->command('app:library:update')->execute(['library' => 'no-such-library', '--name' => 'X']));
        $this->assertJsonResponse($this->authenticatedRequest('PATCH', '/api/libraries/' . Uuid::v7()->toString(), $admin, ['name' => 'X']), 404);
    }

    public function testCreateAndUpdateWithJsonPrintTheLibraryAsTheApiReturnsIt(): void
    {
        $admin = $this->createAdminUser();
        $slug = 'json-' . bin2hex(random_bytes(3));

        $create = $this->command('app:library:create');
        self::assertSame(Command::SUCCESS, $create->execute(['name' => 'JSON library', 'path' => $this->root(), 'type' => 'music', '--slug' => $slug, '--json' => true]), $create->getDisplay());
        $created = json_decode($create->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        $shown = $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/libraries/' . $slug, $admin), 200, 'data')['data'];
        self::assertSame($shown, $created);

        $update = $this->command('app:library:update');
        self::assertSame(Command::SUCCESS, $update->execute(['library' => $slug, '--sort-order' => '4', '--json' => true]), $update->getDisplay());
        $updated = json_decode($update->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        $patched = $this->assertJsonResponse($this->authenticatedRequest('PATCH', '/api/libraries/' . $slug, $admin, ['sortOrder' => 4]), 200, 'data')['data'];
        self::assertSame(4, $updated['sortOrder']);
        self::assertSame(array_diff_key($patched, ['updatedAt' => true]), array_diff_key($updated, ['updatedAt' => true]));
    }

    public function testARootInsideOrContainingAnotherLibrarysRootIsRefusedOnBothPaths(): void
    {
        $admin = $this->createAdminUser();
        $library = $this->createThroughApi('outer-' . bin2hex(random_bytes(3)), $admin);
        $inside = $library['path'] . '/Album';
        $sibling = $library['path'] . '-2';

        $conflict = $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/libraries', $admin, [
            'name' => 'Inside', 'path' => $inside, 'type' => 'music',
        ]), 409);
        self::assertSame(['reason' => 'root_overlaps', 'library' => $library['slug']], $conflict['error']['details']);
        $create = $this->command('app:library:create');
        self::assertSame(Command::FAILURE, $create->execute(['name' => 'Inside', 'path' => $inside, 'type' => 'music']));
        self::assertStringContainsString($conflict['error']['message'], self::flat($create->getDisplay()));

        $containing = $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/libraries', $admin, [
            'name' => 'Containing', 'path' => $this->directory, 'type' => 'music',
        ]), 409);
        self::assertSame(['reason' => 'root_overlaps', 'library' => $library['slug']], $containing['error']['details']);
        self::assertSame(Command::FAILURE, $this->command('app:library:create')->execute(['name' => 'Containing', 'path' => $this->directory, 'type' => 'music']));

        $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/libraries', $admin, [
            'name' => 'Sibling', 'path' => $sibling, 'type' => 'music',
        ]), 201);
    }

    public function testLibraryErrorsAreReportedInTheUsersLanguageElseTheAcceptLanguageElseEnglish(): void
    {
        $admin = $this->createAdminUser();
        $library = $this->createThroughApi('locale-' . bin2hex(random_bytes(3)), $admin);
        $missing = Uuid::v7()->toString();
        // The same root as well: the slug is reported, not the overlap.
        $duplicate = ['name' => 'Again', 'path' => $library['path'], 'type' => 'music', 'slug' => $library['slug']];

        // No saved choice: the browser's language when it is one Baander speaks, else English.
        $english = $this->assertJsonResponse($this->requestIn('POST', '/api/libraries', $admin, 'fr-FR,fr;q=0.9', $duplicate), 409);
        self::assertSame(sprintf('A library with the slug "%s" already exists.', $library['slug']), $english['error']['message']);
        $english = $this->assertJsonResponse($this->requestIn('GET', '/api/libraries/' . $missing, $admin, null), 404);
        self::assertSame(sprintf('Library "%s" not found.', $missing), $english['error']['message']);
        $thai = $this->assertJsonResponse($this->requestIn('GET', '/api/libraries/' . $missing, $admin, 'th'), 404);
        self::assertSame(sprintf('ไม่พบไลบรารี "%s"', $missing), $thai['error']['message']);

        // A saved choice wins over the browser's language.
        $this->service(UserSettingsContractInterface::class)->set($admin->getId()->toString(), 'language', 'da');
        $conflict = $this->assertJsonResponse($this->requestIn('POST', '/api/libraries', $admin, 'th', $duplicate), 409);
        $notFound = $this->assertJsonResponse($this->requestIn('GET', '/api/libraries/' . $missing, $admin, 'th'), 404);

        self::assertSame(sprintf('Et bibliotek med slug "%s" findes allerede.', $library['slug']), $conflict['error']['message']);
        self::assertSame(['reason' => 'slug_exists'], $conflict['error']['details']);
        self::assertSame(sprintf('Biblioteket "%s" blev ikke fundet.', $missing), $notFound['error']['message']);
    }

    /** @param array<string, mixed> $content */
    private function requestIn(string $method, string $uri, User $user, ?string $acceptLanguage, array $content = []): Response
    {
        $server = ['CONTENT_TYPE' => 'application/json', BaanderHeader::TestUserId->serverKey() => $user->getId()->toString()];
        if ($acceptLanguage !== null) {
            $server['HTTP_ACCEPT_LANGUAGE'] = $acceptLanguage;
        }
        $this->client->request($method, $uri, server: $server, content: $content === [] ? null : json_encode($content, JSON_THROW_ON_ERROR));

        return $this->client->getResponse();
    }

    /** @return array<string, mixed> the created LibraryResource */
    private function createThroughApi(string $slug, ?User $admin = null, string $type = 'music'): array
    {
        return $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/libraries', $admin ?? $this->createAdminUser(), [
            'name' => 'Library ' . $slug,
            'slug' => $slug,
            'path' => $this->root(),
            'type' => $type,
        ]), 201, 'data')['data'];
    }

    /** A new library root under this test's directory, beside the others, holding one track. */
    private function root(): string
    {
        $root = $this->directory . '/' . bin2hex(random_bytes(4));
        (new Filesystem())->dumpFile($root . '/Album/track.flac', 'not really audio');

        return $root;
    }

    private function library(string $slug): Library
    {
        $this->entityManager->clear();

        return $this->service(LibraryPortInterface::class)->findBySlug(new LibrarySlug($slug))
            ?? throw new \LogicException(sprintf('Library "%s" is missing.', $slug));
    }

    /** Moves the library's claim lease into the past, as if its scan stopped renewing it. */
    private function lapseClaim(string $libraryId): void
    {
        self::assertSame(1, $this->entityManager->getConnection()->executeStatement(
            "UPDATE libraries SET claim_expires_at = clock_timestamp() - interval '1 second' WHERE id = ? AND claim_id IS NOT NULL",
            [$libraryId],
        ));
        // The update bypassed the unit of work, which the test client's requests share.
        $this->entityManager->clear();
    }

    /** What NotificationBridgeSubscriber dispatches when the outbox relays LibraryScanCompleted. */
    private function notifyScanCompleted(Uuid $libraryId): void
    {
        $event = new LibraryScanCompleted($libraryId, 1, 1);
        $this->service(MessageBusInterface::class)->dispatch(new CreateNotificationCommand(
            LibraryScanCompleted::class,
            $event->toPayload(),
            $event->eventName(),
        ));
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    private function service(string $id): object
    {
        $service = static::getContainer()->get($id);
        self::assertInstanceOf($id, $service);

        return $service;
    }

    private function command(string $name): CommandTester
    {
        return new CommandTester((new Application($this->client->getKernel()))->find($name));
    }

    /** SymfonyStyle wraps long messages; compare them on one line. */
    private static function flat(string $display): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $display));
    }
}
