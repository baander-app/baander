<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalog\Interface\Console;

use App\Auth\Domain\Model\User;
use App\Catalog\Infrastructure\Doctrine\Entity\AlbumEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\ArtistEntity;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Media\Application\Port\StoragePortInterface;
use App\Shared\Application\Http\BaanderHeader;
use App\Shared\Domain\Model\PublicId;
use App\Tests\Functional\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The cover routes and the app:album:cover:* and app:artist:cover:* commands run the same use
 * case against real image storage and report the same outcomes.
 */
final class CoverCommandsTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        $storage = static::getContainer()->get(StoragePortInterface::class);
        $paths = $this->entityManager->getConnection()->fetchFirstColumn(
            "SELECT path FROM images WHERE imageable_type IN ('album', 'artist')",
        );
        foreach ($paths as $path) {
            $storage->delete((string) $path);
        }
        foreach ($this->temporaryFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    public function testReplacingAJpegAlbumCoverWithAJpegKeepsAndServesTheNewImage(): void
    {
        $admin = $this->createAdminUser();
        $album = $this->album();

        $first = $this->uploadOverHttp($admin, 'albums', $album, $this->jpeg(4, 4), 200)['data'];
        $secondFile = $this->jpeg(8, 6);
        $second = $this->uploadOverHttp($admin, 'albums', $album, $secondFile, 200)['data'];

        self::assertNull($this->imagePath($first['publicId']), 'the replaced image record is deleted');
        $secondPath = $this->imagePath($second['publicId']);
        self::assertNotNull($secondPath);
        self::assertFileExists($this->storage()->resolve($secondPath));
        self::assertSame($second['publicId'], $this->coverPublicId('albums', $album));

        $this->client->request('GET', $second['url'], [], [], [BaanderHeader::TestUserId->serverKey() => $admin->getId()->toString()]);
        $served = $this->client->getResponse();
        self::assertSame(200, $served->getStatusCode());
        self::assertInstanceOf(BinaryFileResponse::class, $served);
        self::assertSame(file_get_contents($secondFile), file_get_contents($served->getFile()->getPathname()));

        $set = $this->command('app:album:cover:set');
        self::assertSame(Command::SUCCESS, $set->execute(['public-id' => $album, 'path' => $this->jpeg(8, 6), '--json' => true]), $set->getDisplay());
        $third = json_decode($set->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertNull($this->imagePath($second['publicId']));
        self::assertFileDoesNotExist($this->storage()->resolve($secondPath));
        self::assertFileExists($this->storage()->resolve((string) $this->imagePath($third['publicId'])));
        self::assertSame($third['publicId'], $this->coverPublicId('albums', $album));
    }

    public function testSettingAnArtistCoverFromTheShellGivesTheDataTheApiGives(): void
    {
        $admin = $this->createAdminUser();
        $file = $this->jpeg(8, 6);

        $api = $this->uploadOverHttp($admin, 'artists', $this->artist(), $file, 200)['data'];

        $artist = $this->artist();
        $set = $this->command('app:artist:cover:set');
        self::assertSame(Command::SUCCESS, $set->execute(['public-id' => $artist, 'path' => $file, '--json' => true]), $set->getDisplay());
        $cli = json_decode($set->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(array_keys($api), array_keys($cli));
        self::assertSame('/api/images/' . $cli['publicId'] . '/file', $cli['url']);
        unset($api['publicId'], $api['url'], $cli['publicId'], $cli['url']);
        self::assertSame($api, $cli);
        self::assertSame(['size' => (int) filesize($file), 'width' => 8, 'height' => 6], $cli);
        self::assertFileExists($file, 'the shell file is copied, not moved');
    }

    public function testMalformedIdsAndFilesThatAreNotImagesAreInvalidOnBothPaths(): void
    {
        $admin = $this->createAdminUser();
        $album = $this->album();
        $text = $this->temporaryFile('not an image');

        $this->uploadOverHttp($admin, 'albums', 'not a public id', $this->jpeg(2, 2), 422);
        $this->assertJsonResponse($this->authenticatedRequest('DELETE', '/api/artists/not-a-public-id/cover', $admin), 422);
        $rejected = $this->uploadOverHttp($admin, 'albums', $album, $text, 422);
        self::assertSame('Unsupported image type "text/plain". Allowed: jpeg, png, webp.', $rejected['error']['message']);

        $set = $this->command('app:album:cover:set');
        self::assertSame(Command::INVALID, $set->execute(['public-id' => 'not a public id', 'path' => $this->jpeg(2, 2)]));
        $remove = $this->command('app:artist:cover:remove');
        self::assertSame(Command::INVALID, $remove->execute(['public-id' => 'not-a-public-id', '--force' => true], ['interactive' => false]));
        $notImage = $this->command('app:album:cover:set');
        self::assertSame(Command::INVALID, $notImage->execute(['public-id' => $album, 'path' => $text]));
        $missing = $this->command('app:album:cover:set');
        self::assertSame(Command::INVALID, $missing->execute(['public-id' => $album, 'path' => sys_get_temp_dir() . '/baander-missing-cover.jpg']));

        self::assertNull($this->coverPublicId('albums', $album));
    }

    public function testRemovingACoverThatDoesNotExistIsNotFoundOnBothPaths(): void
    {
        $admin = $this->createAdminUser();
        $artist = $this->artist();

        $this->assertJsonResponse($this->authenticatedRequest('DELETE', '/api/artists/' . $artist . '/cover', $admin), 404);
        $remove = $this->command('app:artist:cover:remove');
        self::assertSame(Command::FAILURE, $remove->execute(['public-id' => $artist, '--force' => true], ['interactive' => false]));

        $cover = $this->uploadOverHttp($admin, 'artists', $artist, $this->jpeg(3, 3), 200)['data'];
        $path = (string) $this->imagePath($cover['publicId']);
        $removed = $this->command('app:artist:cover:remove');
        self::assertSame(Command::SUCCESS, $removed->execute(['public-id' => $artist, '--force' => true], ['interactive' => false]), $removed->getDisplay());
        self::assertNull($this->coverPublicId('artists', $artist));
        self::assertNull($this->imagePath($cover['publicId']));
        self::assertFileDoesNotExist($this->storage()->resolve($path));

        $this->assertJsonResponse($this->authenticatedRequest('DELETE', '/api/artists/' . $artist . '/cover', $admin), 404);
    }

    /** @return array<string, mixed> */
    private function uploadOverHttp(User $admin, string $collection, string $publicId, string $file, int $status): array
    {
        $this->client->request(
            'POST',
            sprintf('/api/%s/%s/cover', $collection, rawurlencode($publicId)),
            [],
            ['cover' => new UploadedFile($file, 'cover.jpg', 'image/jpeg', null, true)],
            [BaanderHeader::TestUserId->serverKey() => $admin->getId()->toString()],
        );

        return $this->assertJsonResponse($this->client->getResponse(), $status);
    }

    private function album(): string
    {
        $suffix = bin2hex(random_bytes(8));
        $library = new LibraryEntity('Cover fixture', 'cover-' . $suffix, '/tmp/cover-' . $suffix, 'music', 'local');
        $album = new AlbumEntity(new PublicId(), $library, 'Cover fixture album', 'album');
        $this->entityManager->persist($library);
        $this->entityManager->persist($album);
        $this->entityManager->flush();

        return $album->getPublicId()->toString();
    }

    private function artist(): string
    {
        $artist = new ArtistEntity(new PublicId(), 'Cover fixture artist');
        $this->entityManager->persist($artist);
        $this->entityManager->flush();

        return $artist->getPublicId()->toString();
    }

    private function imagePath(string $imagePublicId): ?string
    {
        $path = $this->entityManager->getConnection()->fetchOne('SELECT path FROM images WHERE public_id = ?', [$imagePublicId]);

        return $path === false ? null : (string) $path;
    }

    /** @param 'albums'|'artists' $table */
    private function coverPublicId(string $table, string $ownerPublicId): ?string
    {
        $publicId = $this->entityManager->getConnection()->fetchOne(
            sprintf('SELECT i.public_id FROM %s o LEFT JOIN images i ON i.id = o.cover_image_id WHERE o.public_id = ?', $table),
            [$ownerPublicId],
        );

        return is_string($publicId) ? $publicId : null;
    }

    private function jpeg(int $width, int $height): string
    {
        $path = $this->temporaryFile('');
        $canvas = imagecreatetruecolor($width, $height);
        self::assertNotFalse($canvas);
        imagefilledrectangle($canvas, 0, 0, $width - 1, $height - 1, (int) imagecolorallocate($canvas, random_int(0, 255), 40, 90));
        imagejpeg($canvas, $path);

        return $path;
    }

    private function temporaryFile(string $contents): string
    {
        $path = sys_get_temp_dir() . '/baander-cover-' . bin2hex(random_bytes(6));
        file_put_contents($path, $contents);
        $this->temporaryFiles[] = $path;

        return $path;
    }

    private function storage(): StoragePortInterface
    {
        return static::getContainer()->get(StoragePortInterface::class);
    }

    private function command(string $name): CommandTester
    {
        return new CommandTester((new Application($this->client->getKernel()))->find($name));
    }
}
