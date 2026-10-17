<?php

declare(strict_types=1);

namespace App\Tests\Functional\Media;

use App\Media\Application\Command\GenerateImageDerivativeCommand;
use App\Media\Application\CommandHandler\GenerateImageDerivativeHandler;
use App\Media\Application\Port\StoragePortInterface;
use App\Media\Infrastructure\Converter\GdImageConverter;
use App\Media\Infrastructure\Converter\ImageDerivativeGenerator;
use App\Tests\Fixtures\Messaging\MessageCodecFactory;
use PHPUnit\Framework\TestCase;

final class ImageDerivativeWorkerTest extends TestCase
{
    public function testRequestServesTheOriginalUntilWorkerPublication(): void
    {
        if (!function_exists('mime_content_type')) {
            self::markTestSkipped('HTTP file delivery requires fileinfo in the CI runtime.');
        }
        $directory = sys_get_temp_dir() . '/baander-image-response-' . bin2hex(random_bytes(8));
        mkdir($directory);
        imagepng(imagecreatetruecolor(32, 16), $directory . '/source.png');
        $storage = $this->createStub(StoragePortInterface::class);
        $storage->method('resolve')->willReturnCallback(static fn (string $path): string => $directory . '/' . $path);
        $publicId = new \App\Shared\Domain\Model\PublicId();
        $images = $this->createStub(\App\Media\Application\Port\ImagePortInterface::class);
        $images->method('findVisibleByPublicId')->willReturn(new \App\Media\Domain\ReadModel\ImageReadView(
            \App\Shared\Domain\Model\Uuid::v7(), $publicId, 'source.png', 'png', 'image/png', null, 100, 32, 16,
            'album', null, null, null, new \DateTimeImmutable(), new \DateTimeImmutable(),
        ));
        $scope = $this->createStub(\App\Media\Application\Port\MediaReadScopeProviderInterface::class);
        $scope->method('current')->willReturn(\App\Shared\Domain\ValueObject\MediaReadScope::none());
        $transport = new \Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport();
        $bus = new \Symfony\Component\Messenger\MessageBus([
            new \Symfony\Component\Messenger\Middleware\SendMessageMiddleware(
                new \Symfony\Component\Messenger\Transport\Sender\SendersLocator(
                    [GenerateImageDerivativeCommand::class => ['async']],
                    new \Symfony\Component\DependencyInjection\ServiceLocator(['async' => static fn () => $transport]),
                ),
            ),
        ]);
        $controller = new \App\Media\Interface\Controller\ImageController(
            $images, $storage, new \App\Media\Infrastructure\Converter\BlurHashGenerator(new \Psr\Log\NullLogger()),
            new \Psr\Log\NullLogger(), $bus, $scope,
        );
        try {
            $response = $controller->file($publicId->toString(), new \Symfony\Component\HttpFoundation\Request());
            self::assertInstanceOf(\Symfony\Component\HttpFoundation\BinaryFileResponse::class, $response);
            self::assertSame($directory . '/source.png', $response->getFile()->getPathname());
            self::assertCount(1, $transport->getSent());
            self::assertFileDoesNotExist($directory . '/source.webp');
            $worker = new GenerateImageDerivativeHandler(new ImageDerivativeGenerator($storage, new GdImageConverter()));
            $worker($transport->getSent()[0]->getMessage());
            $response = $controller->file($publicId->toString(), new \Symfony\Component\HttpFoundation\Request());
            self::assertInstanceOf(\Symfony\Component\HttpFoundation\BinaryFileResponse::class, $response);
            self::assertSame($directory . '/source.webp', $response->getFile()->getPathname());
            self::assertCount(1, $transport->getSent());
        } finally {
            foreach (glob($directory . '/*') ?: [] as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }

    public function testQueuedDerivativePublishesAWebpAndDuplicateDeliveryConverges(): void
    {
        $directory = sys_get_temp_dir() . '/baander-image-worker-' . bin2hex(random_bytes(8));
        mkdir($directory);
        $source = imagecreatetruecolor(32, 16);
        imagepng($source, $directory . '/source.png');
        $storage = $this->createStub(StoragePortInterface::class);
        $storage->method('resolve')->willReturnCallback(static fn (string $path): string => $directory . '/' . $path);
        $handler = new GenerateImageDerivativeHandler(new ImageDerivativeGenerator($storage, new GdImageConverter()));
        $codec = MessageCodecFactory::create();
        $message = $codec->decode($codec->encode(new GenerateImageDerivativeCommand('source.png', 'thumb')))->message;

        try {
            self::assertFileDoesNotExist($directory . '/source_thumb.webp');
            $handler($message);
            self::assertSame('image/webp', getimagesize($directory . '/source_thumb.webp')['mime']);
            self::assertSame([32, 16], array_slice(getimagesize($directory . '/source_thumb.webp'), 0, 2));
            $published = file_get_contents($directory . '/source_thumb.webp');
            // Removing the original proves duplicate delivery uses the published derivative.
            unlink($directory . '/source.png');
            $handler($message);
            self::assertSame($published, file_get_contents($directory . '/source_thumb.webp'));
        } finally {
            foreach (glob($directory . '/*') ?: [] as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }
}
