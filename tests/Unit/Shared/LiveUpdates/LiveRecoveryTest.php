<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\LiveUpdates;

use App\Auth\Infrastructure\Security\SecurityUser;
use App\Shared\Application\LiveUpdates\LiveAccessDenied;
use App\Shared\Application\LiveUpdates\LiveFamily;
use App\Shared\Application\LiveUpdates\LivePipelineHealth;
use App\Shared\Application\LiveUpdates\LiveSnapshot;
use App\Shared\Application\LiveUpdates\LiveSnapshotReader;
use App\Shared\Domain\Model\Uuid;
use App\Auth\Interface\Controller\LiveRecoveryController;
use App\Shared\Interface\Resource\LiveRecoveryResource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class LiveRecoveryTest extends TestCase
{
    public function testControllerUsesOnlyAuthenticatedPrincipalAndReturnsPrivateResponse(): void
    {
        $principal = new Uuid();
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new SecurityUser($principal->toString(), 'live-recovery@baander.app', 'unused'));
        $reader = $this->createMock(LiveSnapshotReader::class);
        $reader->expects(self::once())->method('read')->with(self::callback(static fn(Uuid $actual): bool => $actual->toString() === $principal->toString()))->willReturn($this->snapshot(null));
        $response = (new LiveRecoveryController($reader, $security))();
        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($response->headers->hasCacheControlDirective('private'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $body = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['version', 'families', 'authorizationFingerprint', 'health'], array_keys($body));
        self::assertSame(['notifications'], $body['families']);
        self::assertStringNotContainsString('scope-secret', (string) $response->getContent());
        self::assertStringNotContainsString($principal->toString(), (string) $response->getContent());
    }

    #[DataProvider('healthCases')]
    public function testHealthReportsBacklogWithoutExposingRevisionVector(?int $age, string $state): void
    {
        $body = LiveRecoveryResource::from($this->snapshot($age));
        self::assertSame(['state' => $state, 'oldestPendingAgeMs' => $age], $body['health']);
        self::assertArrayNotHasKey('revisions', $body);
    }

    /** @return iterable<array{?int, string}> */
    public static function healthCases(): iterable
    {
        yield [null, 'current'];
        yield [0, 'current'];
        yield [5000, 'current'];
        yield [5001, 'degraded'];
    }

    public function testCurrentAuthorityRefusalIsMappedToAccessDenied(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new SecurityUser((new Uuid())->toString(), 'live-recovery@baander.app', 'unused'));
        $reader = $this->createMock(LiveSnapshotReader::class);
        $reader->expects(self::once())->method('read')->willThrowException(new LiveAccessDenied());
        $this->expectException(AccessDeniedException::class);
        (new LiveRecoveryController($reader, $security))();
    }

    public function testFailedSnapshotCannotBecomeASuccessfulEmptyBaseline(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new SecurityUser((new Uuid())->toString(), 'live-recovery@baander.app', 'unused'));
        $reader = $this->createMock(LiveSnapshotReader::class);
        $reader->expects(self::once())->method('read')->willThrowException(new \RuntimeException('Database unavailable.'));
        $this->expectException(\RuntimeException::class);
        (new LiveRecoveryController($reader, $security))();
    }

    public function testUnauthenticatedIdentityCannotReadSnapshot(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null);
        $reader = $this->createMock(LiveSnapshotReader::class);
        $reader->expects(self::never())->method('read');
        $this->expectException(AccessDeniedException::class);
        (new LiveRecoveryController($reader, $security))();
    }

    private function snapshot(?int $age): LiveSnapshot
    {
        return new LiveSnapshot([LiveFamily::Notifications], str_repeat('a', 64), ['notifications:user:scope-secret' => '9123'], new LivePipelineHealth($age));
    }
}
