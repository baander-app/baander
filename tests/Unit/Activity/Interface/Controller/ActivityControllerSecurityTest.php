<?php

declare(strict_types=1);

namespace App\Tests\Unit\Activity\Interface\Controller;

use App\Activity\Application\Port\ActivityPortInterface;
use App\Activity\Domain\Model\MediaActivity;
use App\Activity\Infrastructure\ActivityEnrichmentService;
use App\Activity\Interface\Controller\ActivityController;
use App\Auth\Application\Port\AuthenticatedUserIdentityInterface;
use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Application\Port\ArtistPortInterface;
use App\Catalog\Application\Port\MoviePortInterface;
use App\Catalog\Application\Port\SongPortInterface;
use App\Media\Application\Port\ImagePortInterface;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Security\Core\User\UserInterface;
use App\Activity\Application\Command\ToggleLoveCommand;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ActivityControllerSecurityTest extends TestCase
{
    private Security&Stub $security;
    private ActivityPortInterface&Stub $activityService;
    private MessageBusInterface&MockObject $commandBus;
    private SongPortInterface&Stub $songPort;
    private AlbumPortInterface&Stub $albumPort;
    private ArtistPortInterface&Stub $artistPort;
    private MoviePortInterface&Stub $moviePort;
    private ImagePortInterface&Stub $imagePort;
    private ActivityController $controller;

    protected function setUp(): void
    {
        $this->security = $this->createStub(Security::class);
        $this->activityService = $this->createStub(ActivityPortInterface::class);
        $this->commandBus = $this->createMock(MessageBusInterface::class);
        $this->songPort = $this->createStub(SongPortInterface::class);
        $this->albumPort = $this->createStub(AlbumPortInterface::class);
        $this->artistPort = $this->createStub(ArtistPortInterface::class);
        $this->moviePort = $this->createStub(MoviePortInterface::class);
        $this->imagePort = $this->createStub(ImagePortInterface::class);

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

        $translator = $this->createStub(TranslatorInterface::class);
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

        $intruder = $this->principal($intruderId);
        $this->security->method('getUser')->willReturn($intruder);

        // The toggle command should never be dispatched for an activity the
        // current user does not own.
        $this->commandBus->expects($this->never())->method('dispatch');

        $response = $this->controller->love($activity->getPublicId()->toString());

        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function testLoveAcceptsThePublicOwnerIdentityAndDispatchesItsActivity(): void
    {
        $ownerId = new Uuid();
        $activity = MediaActivity::create(userId: $ownerId, activityType: 'play');
        $this->security->method('getUser')->willReturn($this->principal($ownerId));
        $this->activityService->method('findByPublicId')->willReturn($activity);
        $this->commandBus->expects($this->once())->method('dispatch')
            ->with($this->callback(static fn (ToggleLoveCommand $command): bool => $command->getActivityId()->equals($activity->getId())))
            ->willReturnCallback(static fn (object $command): Envelope => new Envelope($command, [new HandledStamp($activity, 'handler')]));

        $response = $this->controller->love($activity->getPublicId()->toString());

        self::assertSame(200, $response->getStatusCode());
    }

    public function testLoveRejectsPrincipalsWithoutTheApplicationIdentity(): void
    {
        $activity = MediaActivity::create(userId: new Uuid(), activityType: 'play');
        $this->security->method('getUser')->willReturn($this->createStub(UserInterface::class));
        $this->activityService->method('findByPublicId')->willReturn($activity);
        $this->commandBus->expects($this->never())->method('dispatch');

        $response = $this->controller->love($activity->getPublicId()->toString());

        self::assertSame(403, $response->getStatusCode());
    }

    private function principal(Uuid $id): AuthenticatedUserIdentityInterface&UserInterface
    {
        $user = $this->createStubForIntersectionOfInterfaces([AuthenticatedUserIdentityInterface::class, UserInterface::class]);
        $user->method('getId')->willReturn($id->toString());

        self::assertInstanceOf(UserInterface::class, $user);
        self::assertInstanceOf(AuthenticatedUserIdentityInterface::class, $user);

        return $user;
    }

}
