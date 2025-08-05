<?php

namespace App\Jobs\Library\Music;

use App\Jobs\BaseJob;
use App\Models\Album;
use App\Modules\Logging\Attributes\LogChannel;
use App\Modules\Logging\Channel;
use App\Modules\Metadata\MediaMeta\Frame\Apic;
use App\Modules\Metadata\MediaMeta\MediaMeta;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;

class SaveAlbumCoverJob extends BaseJob implements ShouldQueue
{
    #[LogChannel(
        channel: Channel::Metadata,
    )]
    private LoggerInterface $logger;

    /**
     * Create a new job instance.
     */
    public function __construct(
        private Album $album,
        private readonly bool $force = false
    )
    {
    }

    /**
     * Get the middleware the job should pass through.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new WithoutOverlapping("album_cover_{$this->album->id}")];
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->queueProgress(0);

        // Early exit if cover already exists (saves processing time)
        if ($this->album->cover()->exists() && !$this->force) {
            $this->getLogger()->info('Album cover already exists, skipping');
            $this->queueProgress(100);
            return;
        }

        try {
            $song = $this->album->songs()->firstOrFail();
            $mediaMeta = new MediaMeta($song->path);

            $images = $mediaMeta->getImages();
            $imageCount = count($images);

            if ($imageCount === 0) {
                $this->queueProgress(100);
                return;
            }

            $this->queueProgress(50);

            // Use the first image if front cover isn't available
            try {
                $cover = $mediaMeta->getFrontCoverImage() ?: $images[0];
            } catch (Exception $e) {
                $this->getLogger()->warning('Failed to get front cover, using first available image', [
                    'error' => $e->getMessage(),
                    'album_id' => $this->album->id
                ]);
                $cover = $images[0];
            }

            $imageData = $this->createImage($cover);
            $this->queueProgress(75);

            $this->album->cover()->create($imageData);

            // Mark as recently processed to prevent unnecessary future jobs
            cache()->put("album_cover_processed_{$this->album->id}", true, now()->addMinutes(10));

            $this->queueProgress(100);
        } catch (Exception $e) {
            $this->getLogger()->error('Failed to save album cover', [
                'album_id' => $this->album->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        } finally {
            // Clear the queued flag since the job is complete
            cache()->forget("album_cover_queued_{$this->album->id}");
            unset($this->album);
        }
    }

    private function createImage(Apic $artwork): array
    {
        $extension = $this->detectFileExtension($artwork->getImageData());
        $fileName = Str::replace(['/', '\\'], '', Str::ascii($this->album->title)) . '_' . Apic::$types[$artwork->getImageType()];
        $destination = config('image.storage.covers') . DIRECTORY_SEPARATOR . $fileName . '.' . $extension;

        \File::put($destination, $artwork->getImageData());
        $imageInfo = getimagesize($destination);

        return [
            'extension' => $extension,
            'path'      => $destination,
            'mime_type' => $imageInfo['mime'],
            'size'      => $artwork->getImageSize(),
            'width'     => $imageInfo[0],
            'height'    => $imageInfo[1],
        ];
    }

    private function detectFileExtension(string $imageData): string
    {
        $extensions = new \finfo(FILEINFO_EXTENSION)->buffer($imageData);

        if (!is_string($extensions)) {
            throw new \RuntimeException('Unable to parse the correct extension for imagedata');
        }

        $extensions = explode('/', $extensions);

        return $extensions[0];
    }
}