<?php

declare(strict_types=1);

namespace App\Tests\Functional\Library;

use App\Auth\Domain\Model\User;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Functional\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The admin user page (`/api/admin/users/{userId}/libraries`) and the `app:library:member:*`
 * commands grant, revoke and list library access through one use case.
 */
final class LibraryMemberAdministrationTest extends TestCase
{
    public function testGrantingShowsTheLibrarysAlbumsAndRevokingHidesThemOnTheNextRequest(): void
    {
        $admin = $this->createSuperAdminUser();
        $apiMember = $this->createTestUser();
        $cliMember = $this->createTestUser();
        $library = $this->createLibrary();
        $album = $this->createAlbum($library['publicId']);

        foreach ([$apiMember, $cliMember] as $member) {
            self::assertSame(404, $this->authenticatedRequest('GET', '/api/albums/' . $album, $member)->getStatusCode());
        }

        $this->assertJsonResponse($this->authenticatedRequest('PUT', $this->route($apiMember, $library['publicId']), $admin), 200);
        self::assertSame(Command::SUCCESS, $this->runCommand('app:library:member:grant', ['user' => $cliMember->getEmail(), 'library' => $library['slug']])->getStatusCode());

        foreach ([$apiMember, $cliMember] as $member) {
            $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/albums/' . $album, $member), 200);
        }

        $this->assertJsonResponse($this->authenticatedRequest('DELETE', $this->route($apiMember, $library['publicId']), $admin), 200);
        self::assertSame(Command::SUCCESS, $this->runCommand('app:library:member:revoke', ['user' => $cliMember->getPublicId()->toString(), 'library' => $library['publicId']])->getStatusCode());

        foreach ([$apiMember, $cliMember] as $member) {
            self::assertSame(404, $this->authenticatedRequest('GET', '/api/albums/' . $album, $member)->getStatusCode());
        }
    }

    public function testTheApiAndTheConsoleLeaveTheSameStateAndPrintTheSameData(): void
    {
        $admin = $this->createSuperAdminUser();
        $apiMember = $this->createTestUser();
        $cliMember = $this->createTestUser();
        $granted = $this->createLibrary();
        $other = $this->createLibrary();

        $apiGrant = [];
        $cliGrant = [];
        foreach ([1, 2] as $attempt) {
            $apiGrant = $this->assertJsonResponse($this->authenticatedRequest('PUT', $this->route($apiMember, $granted['publicId']), $admin), 200, 'data')['data'];
            $cliGrant = $this->json('app:library:member:grant', ['user' => $cliMember->getEmail(), 'library' => $granted['publicId']]);
        }
        self::assertSame(['libraryId' => $granted['publicId'], 'name' => $granted['name'], 'slug' => $granted['slug'], 'type' => 'music', 'granted' => true], $apiGrant);
        self::assertSame($apiGrant, $cliGrant);
        self::assertSame(1, $this->memberships($apiMember, $granted['publicId']), 'Granting twice stores one membership.');
        self::assertSame(1, $this->memberships($cliMember, $granted['publicId']), 'Granting twice stores one membership.');

        $apiList = $this->assertJsonResponse($this->authenticatedRequest('GET', $this->route($apiMember), $admin), 200, 'data')['data'];
        $cliList = $this->json('app:library:member:list', ['user' => $cliMember->getPublicId()->toString()]);
        // Other tests add libraries to the shared database concurrently; compare this test's own.
        $ownApi = self::only($apiList, [$granted['publicId'], $other['publicId']]);
        self::assertEquals([$granted['publicId'] => true, $other['publicId'] => false], array_map(static fn (array $entry): bool => $entry['granted'], $ownApi));
        self::assertSame($ownApi, self::only($cliList, [$granted['publicId'], $other['publicId']]));

        $apiRevoke = $this->assertJsonResponse($this->authenticatedRequest('DELETE', $this->route($apiMember, $other['publicId']), $admin), 200, 'data')['data'];
        $cliRevoke = $this->json('app:library:member:revoke', ['user' => $cliMember->getEmail(), 'library' => $other['slug']]);
        self::assertFalse($apiRevoke['granted'], 'Revoking access the user lacks succeeds.');
        self::assertSame($apiRevoke, $cliRevoke);
        self::assertSame(1, $this->memberships($apiMember, $granted['publicId']));
        self::assertSame(1, $this->memberships($cliMember, $granted['publicId']));
    }

    public function testAnUnknownUserOrLibraryIsNotFoundOnBothPaths(): void
    {
        $admin = $this->createSuperAdminUser();
        $member = $this->createTestUser();
        $library = $this->createLibrary();
        $unknownUser = (new PublicId())->toString();
        $unknownLibrary = (new PublicId())->toString();

        $apiCalls = [
            ['GET', '/api/admin/users/' . $unknownUser . '/libraries', 'User "' . $unknownUser . '" not found.'],
            ['PUT', '/api/admin/users/' . $unknownUser . '/libraries/' . $library['publicId'], 'User "' . $unknownUser . '" not found.'],
            ['DELETE', '/api/admin/users/' . $unknownUser . '/libraries/' . $library['publicId'], 'User "' . $unknownUser . '" not found.'],
            ['PUT', $this->route($member, $unknownLibrary), 'Library "' . $unknownLibrary . '" not found.'],
            ['DELETE', $this->route($member, $unknownLibrary), 'Library "' . $unknownLibrary . '" not found.'],
        ];
        foreach ($apiCalls as [$method, $uri, $message]) {
            $error = $this->assertJsonResponse($this->authenticatedRequest($method, $uri, $admin), 404, 'error')['error'];
            self::assertSame($message, $error['message'], $method . ' ' . $uri);
        }

        $cliCalls = [
            ['app:library:member:list', ['user' => $unknownUser], 'User "' . $unknownUser . '" not found.'],
            ['app:library:member:grant', ['user' => 'nobody@baander.app', 'library' => $library['slug']], 'User "nobody@baander.app" not found.'],
            ['app:library:member:revoke', ['user' => $unknownUser, 'library' => $library['slug']], 'User "' . $unknownUser . '" not found.'],
            ['app:library:member:grant', ['user' => $member->getPublicId()->toString(), 'library' => $unknownLibrary], 'Library "' . $unknownLibrary . '" not found.'],
            ['app:library:member:revoke', ['user' => $member->getPublicId()->toString(), 'library' => 'no-such-library'], 'Library "no-such-library" not found.'],
        ];
        foreach ($cliCalls as [$name, $arguments, $message]) {
            $tester = $this->runCommand($name, $arguments);
            self::assertSame(Command::FAILURE, $tester->getStatusCode(), $name);
            self::assertStringContainsString($message, self::flat($tester->getErrorOutput()), $name);
            self::assertSame('', $tester->getDisplay(), $name . ' prints nothing on stdout.');
        }

        self::assertSame(0, $this->memberships($member, $library['publicId']));
    }

    public function testOnlySuperAdminsChangeAccessOverHttp(): void
    {
        $admin = $this->createAdminUser();
        $member = $this->createTestUser();
        $library = $this->createLibrary();

        $this->assertJsonResponse($this->authenticatedRequest('PUT', $this->route($member, $library['publicId']), $admin), 403);
        $this->assertJsonResponse($this->authenticatedRequest('PUT', $this->route($member, $library['publicId']), $member), 403);
        $this->assertJsonResponse($this->authenticatedRequest('GET', $this->route($member), $member), 403);
        self::assertSame(0, $this->memberships($member, $library['publicId']));
    }

    private function route(User $user, ?string $libraryId = null): string
    {
        return '/api/admin/users/' . $user->getPublicId()->toString() . '/libraries' . ($libraryId !== null ? '/' . $libraryId : '');
    }

    /** @return array{publicId: string, slug: string, name: string} */
    private function createLibrary(): array
    {
        $suffix = bin2hex(random_bytes(4));
        $library = ['publicId' => (new PublicId())->toString(), 'slug' => 'members-' . $suffix, 'name' => 'Members ' . $suffix];
        $this->entityManager->getConnection()->executeStatement(
            'INSERT INTO libraries (id, public_id, slug, name, path, type, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 0, now(), now())',
            [Uuid::v7()->toString(), $library['publicId'], $library['slug'], $library['name'], '/tmp/baander-members-' . $suffix, 'music'],
        );

        return $library;
    }

    private function createAlbum(string $libraryId): string
    {
        $publicId = (new PublicId())->toString();
        $this->entityManager->getConnection()->executeStatement(
            "INSERT INTO albums (id, public_id, library_id, title, type, year, locked_fields, merged_from, created_at, updated_at)
             VALUES (?, ?, (SELECT id FROM libraries WHERE public_id = ?), 'Members Album', 'studio', 2024, '{}', '[]', now(), now())",
            [Uuid::v7()->toString(), $publicId, $libraryId],
        );

        return $publicId;
    }

    private function memberships(User $user, string $libraryId): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM user_library_access WHERE user_id = ? AND library_id = (SELECT id FROM libraries WHERE public_id = ?)',
            [$user->getId()->toString(), $libraryId],
        );
    }

    /** @param array<string, string> $arguments */
    private function runCommand(string $name, array $arguments): CommandTester
    {
        $tester = new CommandTester((new Application($this->client->getKernel()))->find($name));
        $tester->execute($arguments, ['capture_stderr_separately' => true]);

        return $tester;
    }

    /**
     * @param array<string, string> $arguments
     *
     * @return array<array-key, mixed>
     */
    private function json(string $name, array $arguments): array
    {
        $tester = $this->runCommand($name, $arguments + ['--json' => true]);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getErrorOutput());
        $data = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }

    /**
     * @param array<array-key, mixed> $entries
     * @param list<string>            $libraryIds
     *
     * @return array<string, array<string, mixed>> the entries for these libraries, by library ID, in the list's order
     */
    private static function only(array $entries, array $libraryIds): array
    {
        $own = [];
        foreach ($entries as $entry) {
            self::assertIsArray($entry);
            if (in_array($entry['libraryId'], $libraryIds, true)) {
                $own[(string) $entry['libraryId']] = $entry;
            }
        }

        return $own;
    }

    /** SymfonyStyle wraps long messages; compare them on one line. */
    private static function flat(string $display): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $display));
    }
}
