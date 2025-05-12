<?php

namespace App\Jobs\Library\Music;

use App\Jobs\BaseJob;
use App\Models\Album;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use App\Modules\MediaMeta\Frame\Apic;
use App\Modules\MediaMeta\MediaMeta;
use Illuminate\Support\Facades\Log;

class SaveAlbumCoverJob extends BaseJob implements ShouldQueue
{
    private Album $album;

    /**
     * Create a new job instance.
     */
    public function __construct(Album $album)
    {
        $this->album = $album;
    }

    /**
     * Get the middleware the job should pass through.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new WithoutOverlapping("album_cover_{$this->album->id}")->dontRelease()];
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->queueProgress(0);

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
            } catch (\Exception $e) {
                Log::warning('Failed to get front cover, using first available image', [
                    'error' => $e->getMessage(),
                    'album_id' => $this->album->id
                ]);
                $cover = $images[0];
            }

            $imageData = $this->createImage($cover);
            $this->queueProgress(75);

            $this->album->cover()->create($imageData);
            $this->queueProgress(100);
        } catch (\Exception $e) {
            Log::error('Failed to save album cover', [
                'album_id' => $this->album->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        } finally {
            // Clean up resources
            unset($this->album);
        }
    }

    private function createImage(Apic $artwork): array
    {
        $extension = $this->detectFileExtension($artwork->getImageData());
        $fileName = $this->album->title . '_' . Apic::$types[$artwork->getImageType()];
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