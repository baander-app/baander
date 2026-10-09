<?php

declare(strict_types=1);

namespace App\Catalog\Application\CommandHandler\Cover;

use App\Catalog\Application\Command\Cover\CoverImageView;
use App\Catalog\Application\Command\Cover\CoverOwner;
use App\Catalog\Application\Command\Cover\SetCoverCommand;
use App\Filesystem\Application\Port\MimeDetectorPortInterface;
use App\Media\Application\Port\ImagePortInterface;
use App\Media\Application\Port\StoragePortInterface;
use App\Media\Domain\Model\Image;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\Uuid;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;
use App\Catalog\Application\Service\CoverOwners;
use App\Catalog\Application\Service\CoverImageDiscarder;

/**
 * Stores an image file as the cover of an album or an artist.
 *
 * Each cover gets its own storage path, so a replacement never overwrites the file it replaces.
 * The image record and the owner are saved in one transaction; when that fails before commit,
 * the new file is removed and the old cover stays. The old cover is deleted only after commit.
 */
final readonly class SetCoverHandler
{
    public const int MAX_SIZE = 10 * 1024 * 1024;

    private const array EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function __construct(
        private CoverOwners $owners,
        private ImagePortInterface $images,
        private StoragePortInterface $storage,
        private MimeDetectorPortInterface $mimeDetector,
        private TransactionPortInterface $transaction,
        private CoverImageDiscarder $discarder,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws InvalidInputException when the public ID is malformed, or the file is missing, too large or not a JPEG, PNG or WebP image
     * @throws NotFoundException     when no album or artist has the public ID
     */
    #[AsMessageHandler]
    public function __invoke(SetCoverCommand $command): CoverImageView
    {
        $owner = $this->owners->find($command->owner, $command->publicId);
        [$mimeType, $extension] = $this->inspect($command->path);
        $dimensions = @getimagesize($command->path);

        $relativePath = sprintf(
            'images/%s/%s/%s.%s',
            $command->owner->value,
            $owner->getId()->toString(),
            Uuid::generate()->toString(),
            $extension,
        );
        $stored = $this->storage->store($command->path, $relativePath);

        $image = Image::create(
            path: $stored->getPath(),
            extension: $extension,
            mimeType: $mimeType,
            size: $stored->getSize(),
            width: $dimensions !== false ? $dimensions[0] : 0,
            height: $dimensions !== false ? $dimensions[1] : 0,
            imageableType: $command->owner->value,
            albumId: $command->owner === CoverOwner::Album ? $owner->getId() : null,
            artistId: $command->owner === CoverOwner::Artist ? $owner->getId() : null,
        );
        $previousImageId = $owner->getCoverImageId();

        $saved = false;
        try {
            $this->transaction->run(function () use ($image, $owner, &$saved): void {
                $this->images->save($image);
                $owner->setCoverImage($image->getId());
                $this->owners->save($owner);
                $saved = true;
            });
        } catch (Throwable $exception) {
            // Once both saves went through, only the commit can have failed, and a failed commit
            // may have succeeded on the server. Keep the file rather than break a committed cover.
            if (!$saved) {
                $this->removeUncommittedFile($stored->getPath());
            }

            throw $exception;
        }

        if ($previousImageId !== null) {
            $this->discarder->discard($previousImageId);
        }

        return new CoverImageView(
            publicId: $image->getPublicId()->toString(),
            size: $image->getSize(),
            width: $image->getWidth(),
            height: $image->getHeight(),
        );
    }

    /**
     * @return array{string, string} the detected MIME type and the extension it is stored with
     *
     * @throws InvalidInputException when the file is missing, unreadable, too large or not a supported image
     */
    private function inspect(string $path): array
    {
        $size = is_file($path) && is_readable($path) ? filesize($path) : false;
        if ($size === false) {
            throw new InvalidInputException('The cover file does not exist or cannot be read.');
        }

        if ($size > self::MAX_SIZE) {
            throw new InvalidInputException('File size exceeds maximum of 10 MB.');
        }

        $mimeType = $this->mimeDetector->detect($path);
        if (!isset(self::EXTENSIONS[$mimeType])) {
            throw new InvalidInputException(sprintf('Unsupported image type "%s". Allowed: jpeg, png, webp.', $mimeType));
        }

        return [$mimeType, self::EXTENSIONS[$mimeType]];
    }

    private function removeUncommittedFile(string $path): void
    {
        try {
            $this->storage->delete($path);
        } catch (Throwable $exception) {
            $this->logger->error('Failed to remove the file of a cover that was not saved', [
                'path' => $path,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
