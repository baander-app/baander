<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media\Infrastructure\Admin;

use App\Media\Application\Port\StoragePortInterface;
use App\Media\Domain\Model\Image;
use App\Media\Domain\Repository\ImageRepositoryInterface;
use App\Media\Infrastructure\Admin\MediaAdminService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\MessageBusInterface;

final class MediaAdminIdentityTest extends TestCase
{
    public function testMissingImageReportUsesPublicIdentityWhileInternalScanCursorRemainsUuid(): void
    {
        $image = Image::create('/covers/missing.jpg', 'jpg', 'image/jpeg', 1, 10, 10, 'album');
        $images = $this->createMock(ImageRepositoryInterface::class);
        $images->method('countAll')->willReturn(1);
        $images->expects(self::exactly(2))->method('findAllAfter')->willReturnOnConsecutiveCalls([$image], []);
        $storage = $this->createStub(StoragePortInterface::class);
        $storage->method('exists')->willReturn(false);
        $service = new MediaAdminService(
            $images, $storage, $this->createStub(EntityManagerInterface::class),
            $this->createStub(MessageBusInterface::class), new NullLogger(),
        );
        $report = $service->checkMissingImages();
        self::assertSame([['publicId' => $image->getPublicId()->toString(), 'path' => $image->getPath(), 'type' => 'album']], $report['missingImages']);
        self::assertStringNotContainsString($image->getId()->toString(), json_encode($report, JSON_THROW_ON_ERROR));
    }
}
