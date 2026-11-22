<?php

declare(strict_types=1);

namespace App\Tests\Functional\Media\Interface\Controller;

use App\Media\Application\Port\StoragePortInterface;
use App\Media\Infrastructure\Doctrine\Entity\ImageEntity;
use App\Shared\Domain\Model\PublicId;
use App\Tests\Functional\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The image file route serves the media type recorded for the image, never one sniffed from the
 * file's bytes, so a stored file that parses as markup can never run as a page at the app origin.
 */
final class ImageFileResponseTest extends TestCase
{
    private const string SVG = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';

    /** @var list<string> */
    private array $storedPaths = [];

    #[\Override]
    protected function tearDown(): void
    {
        $storage = self::getContainer()->get(StoragePortInterface::class);
        foreach ($this->storedPaths as $path) {
            $storage->delete($path);
        }

        parent::tearDown();
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function allowedTypes(): iterable
    {
        yield 'gif' => ['gif', 'image/gif', 'image/gif'];
        yield 'webp' => ['webp', 'image/webp', 'image/webp'];
    }

    #[DataProvider('allowedTypes')]
    public function testAnAllowedStoredTypeIsServedEvenWhenTheBytesSniffAsMarkup(string $extension, string $storedType, string $servedType): void
    {
        $publicId = $this->storeImage(self::SVG, $extension, $storedType);

        $response = $this->authenticatedRequest('GET', '/api/images/' . $publicId . '/file', $this->createAdminUser());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame($servedType, $response->headers->get('Content-Type'));
        self::assertNull($response->headers->get('Content-Disposition'));
        self::assertSame("default-src 'none'; sandbox", $response->headers->get('Content-Security-Policy'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function testAStoredTypeOutsideTheAllowlistIsServedAsAnAttachment(): void
    {
        $publicId = $this->storeImage(self::SVG, 'gif', 'image/svg+xml');

        $response = $this->authenticatedRequest('GET', '/api/images/' . $publicId . '/file', $this->createAdminUser());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/octet-stream', $response->headers->get('Content-Type'));
        self::assertStringStartsWith('attachment', (string) $response->headers->get('Content-Disposition'));
        self::assertSame("default-src 'none'; sandbox", $response->headers->get('Content-Security-Policy'));
    }

    private function storeImage(string $contents, string $extension, string $storedType): PublicId
    {
        $path = 'images/test/' . bin2hex(random_bytes(8)) . '.' . $extension;
        self::getContainer()->get(StoragePortInterface::class)->storeFromBytes($contents, $path);
        $this->storedPaths[] = $path;

        $publicId = new PublicId();
        $this->entityManager->persist(new ImageEntity($path, $extension, $storedType, $publicId, \strlen($contents), 1, 1, 'album'));
        $this->entityManager->flush();

        return $publicId;
    }
}
