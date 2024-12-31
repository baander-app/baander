<?php

namespace App\Jobs\Library\Music;

use App\Jobs\BaseJob;
use App\Models\Album;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Safe\Exceptions\ImageException;
use Zend_Media_Id3_Frame_Apic;
use Zend_Media_Id3v2;

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
        return [(new WithoutOverlapping("album_cover_{$this->album->id}"))->dontRelease()];
    }

    /**
     * Execute the job.
     * @throws \Zend_Media_Id3_Exception
     * @throws ImageException
     */
    public function handle(): void
    {
        $this->queueProgress(0);

        $song = $this->album->songs()->firstOrFail();
        $id3 = new Zend_Media_Id3v2($song->path);
        $frames = $id3->getFramesByIdentifier('APIC');

        $this->queueProgress(50);

        if (count($frames) > 0) {
            [
                'extension' => $extension,
                'path'      => $path,
                'mime_type' => $mime_type,
                'size'      => $size,
                'width'     => $width,
                'height'    => $height,
            ] = $this->createImage($frames[0]);

            $this->queueProgress(75);
            $this->album->cover()->create([
                'extension' => $extension,
                'path'      => $path,
                'mime_type' => $mime_type,
                'size'      => $size,
                'width'     => $width,
                'height'    => $height,
            ]);
        }

        $this->queueProgress(100);
    }

    /**
     * @throws ImageException
     */
    private function createImage(Zend_Media_Id3_Frame_Apic $artwork): array
    {
        $extension = $this->detectFileExtension($artwork->getImageData());
        $fileName = $this->album->title . '_' . Zend_Media_Id3_Frame_Apic::$types[$artwork->getImageType()];
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
        $finfo = new \finfo(FILEINFO_EXTENSION);
        $extensions = $finfo->buffer($imageData);

        if (!is_string($extensions)) {
            throw new \RuntimeException('Unable to parse the correct extension for imagedata');
        }

        $extensions = explode('/', $extensions);

        return $extensions[0];
    }
}
