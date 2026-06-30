<?php

declare(strict_types=1);

namespace App\Tests\Unit\Activity\Application\CommandHandler;

use App\Activity\Application\Command\ToggleLoveCommand;
use App\Activity\Application\CommandHandler\ToggleLoveHandler;
use App\Activity\Domain\Model\MediaActivity;
use App\Activity\Domain\Repository\MediaActivityRepositoryInterface;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ToggleLoveHandlerTest extends TestCase
{
    private MediaActivityRepositoryInterface&MockObject $repository;
    private ToggleLoveHandler $handler;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(MediaActivityRepositoryInterface::class);
        $this->handler = new ToggleLoveHandler($this->repository);
    }

    public function testTogglesLoveFromFalseToTrue(): void
    {
        $activity = MediaActivity::create(userId: Uuid::v4(), activityType: 'play');
        $activityId = $activity->getId();

        $this->assertFalse($activity->isLove());

        $this->repository->expects($this->once())
            ->method('findByUuid')
            ->with($activityId)
            ->willReturn($activity);
        $this->repository->expects($this->once())->method('save')->with($activity);

        $result = ($this->handler)(new ToggleLoveCommand($activityId));

        $this->assertSame($activity, $result);
        $this->assertTrue($result->isLove());
    }

    public function testTogglesLoveFromTrueToFalse(): void
    {
        $activity = MediaActivity::create(userId: Uuid::v4(), activityType: 'love');
        $activity->setLove(true);
        $activityId = $activity->getId();

        $this->repository->expects($this->once())
            ->method('findByUuid')
            ->with($activityId)
            ->willReturn($activity);
        $this->repository->expects($this->once())->method('save')->with($activity);

        $result = ($this->handler)(new ToggleLoveCommand($activityId));

        $this->assertSame($activity, $result);
        $this->assertFalse($result->isLove());
    }

    public function testThrowsWhenActivityNotFound(): void
    {
        $activityId = Uuid::v4();

        $this->repository->expects($this->once())
            ->method('findByUuid')
            ->with($activityId)
            ->willReturn(null);
        $this->repository->expects($this->never())->method('save');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Activity not found.');

        ($this->handler)(new ToggleLoveCommand($activityId));
    }

    public function testInvokeIsTheAsMessageHandler(): void
    {
        // The handler exposes __invoke; ensure it returns the saved activity
        $activity = MediaActivity::create(userId: Uuid::v4(), activityType: 'play');
        $activityId = $activity->getId();

        $this->repository->expects($this->once())
            ->method('findByUuid')
            ->willReturn($activity);
        $this->repository->expects($this->once())->method('save');

        $returned = $this->handler->__invoke(new ToggleLoveCommand($activityId));

        $this->assertSame($activity, $returned);
        $this->assertTrue($returned->isLove());
    }
}
