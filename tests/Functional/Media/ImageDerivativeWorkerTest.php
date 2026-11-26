<?php

declare(strict_types=1);

namespace App\Tests\Functional\Media;

use App\Media\Application\Command\GenerateImageDerivativeCommand;
use App\Media\Application\CommandHandler\GenerateImageDerivativeHandler;
use App\Media\Application\Port\ImagePortInterface;
use App\Media\Application\Port\MediaReadScopeProviderInterface;
use App\Media\Application\Port\StoragePortInterface;
use App\Media\Domain\ReadModel\ImageReadView;
use App\Media\Infrastructure\Converter\ImageConversionAdapter;
use App\Media\Infrastructure\Converter\ImageDerivativeGenerator;
use App\Media\Interface\Controller\ImageController;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\MediaReadScope;
use App\Tests\Fixtures\Messaging\MessageCodecFactory;
use Baander\ImageProcessing\BlurHash\BlurHashGenerator;
use Baander\ImageProcessing\GdImageConverter as ImageProcessor;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;

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
        $publicId = new PublicId();
        $images = $this->createStub(ImagePortInterface::class);
        $images->method('findVisibleByPublicId')->willReturn(new ImageReadView(
            Uuid::v7(), $publicId, 'source.png', 'png', 'image/png', null, 100, 32, 16,
            'album', null, null, null, new \DateTimeImmutable(), new \DateTimeImmutable(),
        ));
        $scope = $this->createStub(MediaReadScopeProviderInterface::class);
        $scope->method('current')->willReturn(MediaReadScope::none());
        $transport = new InMemoryTransport();
        $bus = new MessageBus([
            new SendMessageMiddleware(
                new SendersLocator(
                    [GenerateImageDerivativeCommand::class => ['async']],
                    new ServiceLocator(['async' => static fn () => $transport]),
                ),
            ),
        ]);
        $controller = new ImageController(
            $images, $storage, new BlurHashGenerator(new NullLogger()),
            new NullLogger(), $bus, $scope,
        );
        try {
            $response = $controller->file($publicId->toString(), new Request());
            self::assertInstanceOf(BinaryFileResponse::class, $response);
            self::assertSame($directory . '/source.png', $response->getFile()->getPathname());
            self::assertCount(1, $transport->getSent());
            self::assertFileDoesNotExist($directory . '/source.webp');
            $worker = new GenerateImageDerivativeHandler(new ImageDerivativeGenerator($storage, new ImageConversionAdapter(new ImageProcessor())));
            $worker($transport->getSent()[0]->getMessage());
            $response = $controller->file($publicId->toString(), new Request());
            self::assertInstanceOf(BinaryFileResponse::class, $response);
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
        $handler = new GenerateImageDerivativeHandler(new ImageDerivativeGenerator($storage, new ImageConversionAdapter(new ImageProcessor())));
        $codec = MessageCodecFactory::create();
        $message = $codec->decode($codec->encode(new GenerateImageDerivativeCommand('source.png', 'thumb')))->message;

        try {
            self::assertFileDoesNotExist($directory . '/source_thumb.webp');
            $handler($message);
            self::assertSame('image/webp', getimagesize($directory . '/source_thumb.webp')['mime']);
            $thumbnailSize = array_slice(getimagesize($directory . '/source_thumb.webp'), 0, 2);
            self::assertSame([32, 16], $thumbnailSize);
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
