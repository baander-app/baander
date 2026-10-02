<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Interface\Controller;

use App\Catalog\Application\Command\BatchExtractCoversCommand;
use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Interface\Controller\ExtractCoversController;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class ExtractCoversControllerTest extends TestCase
{
    public function testQueuesCoverExtractionThroughCatalogCommandAndReturnsAlbumCount(): void
    {
        $albums = $this->createMock(AlbumPortInterface::class);
        $albums->expects($this->once())->method('countCoverlessAlbums')->willReturn(17);
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())->method('dispatch')->willReturnCallback(
            static function (object $command): Envelope {
                self::assertInstanceOf(BatchExtractCoversCommand::class, $command);
                return new Envelope($command);
            },
        );

        $response = (new ExtractCoversController($albums, $bus))();

        self::assertSame(Response::HTTP_ACCEPTED, $response->getStatusCode());
        self::assertSame(['data' => ['albums' => 17]], json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function testDispatchFailurePropagatesInsteadOfReturningAccepted(): void
    {
        $albums = $this->createMock(AlbumPortInterface::class);
        $albums->expects($this->once())->method('countCoverlessAlbums')->willReturn(17);
        $failure = new RuntimeException('Cover fanout unavailable');
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())->method('dispatch')
            ->with($this->isInstanceOf(BatchExtractCoversCommand::class))
            ->willThrowException($failure);

        try {
            (new ExtractCoversController($albums, $bus))();
        } catch (RuntimeException $actual) {
            self::assertSame($failure, $actual);
            return;
        }
        self::fail('Failed dispatch must not return an accepted response.');
    }
}
