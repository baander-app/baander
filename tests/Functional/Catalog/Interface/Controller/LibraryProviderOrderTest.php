<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalog\Interface\Controller;

use App\Catalog\Domain\Repository\LibraryProviderOrderRepositoryInterface;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Tests\Functional\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class LibraryProviderOrderTest extends TestCase
{
    public function testDefaultOrdersReadByPublicIdOrSlugDoNotCreateRows(): void
    {
        $admin = $this->createAdminUser();
        $tv = $this->library('tv_show');
        $movie = $this->library('movie');
        $orders = static::getContainer()->get(LibraryProviderOrderRepositoryInterface::class);
        foreach ([[$tv, ['tvdb', 'tmdb', 'local']], [$movie, ['tmdb', 'local']]] as [$library, $expected]) {
            self::assertNull($orders->findByLibrary($library->getId()));
            $byPublicId = $this->assertJsonResponse($this->authenticatedRequest('GET', $this->path($library), $admin), 200)['data'];
            $bySlug = $this->assertJsonResponse($this->authenticatedRequest('GET', $this->path($library, true), $admin), 200)['data'];
            self::assertSame(['providers' => $expected], $byPublicId);
            self::assertSame($byPublicId, $bySlug);
            self::assertNull($orders->findByLibrary($library->getId()));
        }
    }

    public function testHttpAndConsoleReplaceAndReadTheSameOrder(): void
    {
        $admin = $this->createAdminUser();
        $library = $this->library('tv_show');
        $replacement = ['providers' => ['tmdb', 'local', 'tvdb']];
        $updated = $this->assertJsonResponse($this->authenticatedRequest('PUT', $this->path($library), $admin, $replacement), 200)['data'];
        self::assertSame($replacement, $updated);
        $application = new Application($this->client->getKernel());
        $read = new CommandTester($application->find('app:video:providers:order'));
        self::assertSame(Command::SUCCESS, $read->execute(['library' => $library->getSlug(), '--json' => true]));
        self::assertSame($updated, json_decode($read->getDisplay(), true, flags: JSON_THROW_ON_ERROR));
        $write = new CommandTester($application->find('app:video:providers:order'));
        self::assertSame(Command::SUCCESS, $write->execute(['library' => $library->getPublicId()->toString(), '--providers' => 'local,tvdb,tmdb', '--json' => true]));
        $saved = $this->assertJsonResponse($this->authenticatedRequest('GET', $this->path($library), $admin), 200)['data'];
        self::assertSame(['providers' => ['local', 'tvdb', 'tmdb']], $saved);
        self::assertSame($saved, json_decode($write->getDisplay(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testAnonymousMembersAndDelegatedAdministratorsCannotReadOrReplaceTheOrder(): void
    {
        $admin = $this->createAdminUser();
        $member = $this->createTestUser();
        $library = $this->library('tv_show');
        $path = $this->path($library);
        $replacement = ['providers' => ['local', 'tvdb', 'tmdb']];
        foreach (['GET', 'PUT'] as $method) {
            $body = $method === 'PUT' ? $replacement : [];
            self::assertSame(401, $this->anonymousRequest($method, $path, $body)->getStatusCode());
            self::assertSame(403, $this->authenticatedRequest($method, $path, $member, $body)->getStatusCode());
            self::assertSame(403, $this->delegatedRequest($method, $path, $admin, ['profile', 'library', 'playlist', 'party', 'device'], $body)->getStatusCode());
        }
        $order = $this->assertJsonResponse($this->authenticatedRequest('GET', $path, $admin), 200)['data'];
        self::assertSame(['providers' => ['tvdb', 'tmdb', 'local']], $order);
        self::assertNull(static::getContainer()->get(LibraryProviderOrderRepositoryInterface::class)->findByLibrary($library->getId()));
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidOrders(): iterable
    {
        yield 'missing provider' => [['tmdb']];
        yield 'duplicate provider' => [['tmdb', 'tmdb']];
        yield 'unsupported provider' => [['tmdb', 'tvdb', 'local']];
        yield 'wrong provider type' => [['tmdb', false]];
        yield 'empty list' => [[]];
        yield 'object instead of list' => [['first' => 'tmdb', 'second' => 'local']];
        yield 'string instead of list' => ['tmdb,local'];
    }

    #[DataProvider('invalidOrders')]
    public function testInvalidOrdersLeaveTheSavedOrderIntact(mixed $providers): void
    {
        $admin = $this->createAdminUser();
        $library = $this->library('movie');
        $path = $this->path($library);
        $this->assertJsonResponse($this->authenticatedRequest('PUT', $path, $admin, ['providers' => ['local', 'tmdb']]), 200);
        $this->assertJsonResponse($this->authenticatedRequest('PUT', $path, $admin, ['providers' => $providers]), 422);
        self::assertSame(['providers' => ['local', 'tmdb']], $this->assertJsonResponse($this->authenticatedRequest('GET', $path, $admin), 200)['data']);
    }

    public function testUnsupportedLibraryAndMissingIdentifiersHaveSharedHttpAndConsoleErrors(): void
    {
        $admin = $this->createAdminUser();
        $music = $this->library('music');
        foreach (['GET', 'PUT'] as $method) {
            $body = $method === 'PUT' ? ['providers' => ['tmdb', 'local']] : [];
            $this->assertJsonResponse($this->authenticatedRequest($method, $this->path($music), $admin, $body), 422);
            $this->assertJsonResponse($this->authenticatedRequest($method, '/api/admin/video-matches/libraries/missing-provider-order/provider-order', $admin, $body), 404);
        }
        $application = new Application($this->client->getKernel());
        $command = new CommandTester($application->find('app:video:providers:order'));
        self::assertSame(Command::INVALID, $command->execute(['library' => $music->getSlug()]));
        self::assertSame(Command::FAILURE, $command->execute(['library' => 'missing-provider-order']));
    }

    private function library(string $type): LibraryEntity
    {
        $slug = 'provider-order-' . bin2hex(random_bytes(6));
        $library = new LibraryEntity('Provider order', $slug, '/media/' . $slug, $type, 'local');
        $this->entityManager->persist($library);
        $this->entityManager->flush();

        return $library;
    }

    private function path(LibraryEntity $library, bool $slug = false): string
    {
        $identifier = $slug ? $library->getSlug() : $library->getPublicId()->toString();

        return '/api/admin/video-matches/libraries/' . $identifier . '/provider-order';
    }
}
