<?php

declare(strict_types=1);

namespace App\Tests\Unit\Activity\Interface\Controller;

use App\Activity\Application\Port\ActivityPortInterface;
use App\Activity\Domain\Model\MediaActivity;
use App\Activity\Infrastructure\ActivityEnrichmentService;
use App\Activity\Interface\Controller\ActivityController;
use App\Auth\Infrastructure\Security\SecurityUser;
use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Application\Port\ArtistPortInterface;
use App\Catalog\Application\Port\MoviePortInterface;
use App\Catalog\Application\Port\SongPortInterface;
use App\Media\Application\Port\ImagePortInterface;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Security-focused tests for ActivityController.
 *
 * The love() endpoint currently toggles the love state of any activity
 * without verifying that the caller owns the activity. These tests assert
 * the expected secure behaviour and fail against the current production code.
 */
final class ActivityControllerSecurityTest extends TestCase
{
    private Security&MockObject $security;
    private ActivityPortInterface&MockObject $activityService;
    private MessageBusInterface&MockObject $commandBus;
    private SongPortInterface&MockObject $songPort;
    private AlbumPortInterface&MockObject $albumPort;
    private ArtistPortInterface&MockObject $artistPort;
    private MoviePortInterface&MockObject $moviePort;
    private ImagePortInterface&MockObject $imagePort;
    private ActivityController $controller;

    protected function setUp(): void
    {
        $this->security = $this->createMock(Security::class);
        $this->activityService = $this->createMock(ActivityPortInterface::class);
        $this->commandBus = $this->createMock(MessageBusInterface::class);
        $this->songPort = $this->createMock(SongPortInterface::class);
        $this->albumPort = $this->createMock(AlbumPortInterface::class);
        $this->artistPort = $this->createMock(ArtistPortInterface::class);
        $this->moviePort = $this->createMock(MoviePortInterface::class);
        $this->imagePort = $this->createMock(ImagePortInterface::class);

        $enrichmentService = new ActivityEnrichmentService(
            songService: $this->songPort,
            albumService: $this->albumPort,
            imageService: $this->imagePort,
            movieService: $this->moviePort,
        );

        $this->controller = new ActivityController(
            security: $this->security,
            activityService: $this->activityService,
            commandBus: $this->commandBus,
            enrichmentService: $enrichmentService,
            songPort: $this->songPort,
            albumPort: $this->albumPort,
            artistPort: $this->artistPort,
            moviePort: $this->moviePort,
        );

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);
        $this->controller->setTranslator($translator);
    }

    public function testLoveRejectsActivityOwnedByAnotherUser(): void
    {
        $ownerId = Uuid::v4();
        $intruderId = Uuid::v4();

        $activity = MediaActivity::create(
            userId: $ownerId,
            activityType: 'play',
        );

        $this->activityService->method('findByPublicId')->willReturn($activity);

        $intruder = new SecurityUser($intruderId->toString(), 'intruder@example.com', 'hashed', ['ROLE_USER']);
        $this->security->method('getUser')->willReturn($intruder);

        // The toggle command should never be dispatched for an activity the
        // current user does not own.
        $this->commandBus->expects($this->never())->method('dispatch');

        $response = $this->controller->love($activity->getPublicId()->toString());

        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }
}
