<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media\Infrastructure;

use App\Library\Application\Port\LibraryReadScopeProviderInterface;
use App\Media\Infrastructure\MediaReadScopeProvider;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

final class MediaReadScopeProviderTest extends TestCase
{
    public function testExplicitAccountComposesExplicitLibraryScopeWithoutAmbientUser(): void
    {
        $owner = new Uuid();
        $scope = LibraryReadScope::restricted([new Uuid()]);
        $security = $this->createMock(Security::class);
        $security->expects(self::never())->method('getUser');
        $libraries = $this->createMock(LibraryReadScopeProviderInterface::class);
        $libraries->expects(self::once())->method('forUser')->with($owner)->willReturn($scope);
        $result = new MediaReadScopeProvider($security, $libraries)->forUser($owner);
        self::assertSame($owner, $result->getActorId());
        self::assertSame($scope, $result->getLibraries());
    }
}
