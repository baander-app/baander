<?php

declare(strict_types=1);

namespace App\Tests\Unit\Activity\Interface\Controller;

use App\Activity\Application\Port\ActivityPortInterface;
use App\Activity\Infrastructure\ActivityEnrichmentService;
use App\Activity\Interface\Controller\UserRecentController;
use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Application\Port\MoviePortInterface;
use App\Catalog\Application\Port\SongPortInterface;
use App\Media\Application\Port\ImagePortInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\UserInterface;

final class RecentIdentityTest extends TestCase
{
    public function testUnsupportedPrincipalCannotReadRecentActivity(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->createStub(UserInterface::class));
        $activities = $this->createMock(ActivityPortInterface::class);
        $activities->expects($this->never())->method('getRecentlyPlayed');
        $enrichment = new ActivityEnrichmentService(
            $this->createStub(SongPortInterface::class),
            $this->createStub(AlbumPortInterface::class),
            $this->createStub(ImagePortInterface::class),
            $this->createStub(MoviePortInterface::class),
        );
        $controller = new UserRecentController($security, $activities, $enrichment);

        self::assertSame(401, $controller->recent(new Request())->getStatusCode());
    }
}
