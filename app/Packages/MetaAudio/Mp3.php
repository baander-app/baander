<?php

namespace App\Packages\MetaAudio;

use App\Packages\StrExt;
use Symfony\Component\String\UnicodeString;

/**
 * Read/write tags from an mp3 file.
 */
class Mp3 implements ModuleManagerInterface
{
    use ModuleManager;

    /**
     * @var FileInterface $file The file handler.
     */
    private $file;


    /**
     * Create a new instance from a local file.
     *
     * @param FileInterface $file The file to work with
     */
    public function __construct(FileInterface $file)
    {
        $this->file = $file;
    }

    private function getModuleStringOrNull(string $method): string|null
    {
        foreach ($this->getModules() as $module) {
            $module->open($this->file);
            $result = $module->$method();
            if (is_string($result) && $result !== '') {
                $str = new UnicodeString($result);

                return $str->trim()->toString();
            }
        }

        return null;
    }

    private function getModuleIntOrNull(string $method): int|null
    {
        foreach ($this->getModules() as $module) {
            $module->open($this->file);
            $result = $module->$method();
            if (is_numeric($result) && $result > 0) {
                return (int)$result;
            }
        }

        return null;
    }


    /**
     * Get the track title.
     */
    public function getTitle(): string|null
    {
        return $this->getModuleStringOrNull(__FUNCTION__);
    }

    public function getAlbumTitle(): string|null
    {
        return $this->getModuleStringOrNull(__FUNCTION__);
    }

    public function getLength(): string|null
    {
        return $this->getModuleStringOrNull(__FUNCTION__);
    }


    public function getComments(): string|null
    {
        return $this->getModuleStringOrNull(__FUNCTION__);
    }

    public function getUnsychronizedLyrics(): string|null
    {
        return $this->getModuleStringOrNull(__FUNCTION__);
    }

    /**
     * Get the track number.
     */
    public function getTrackNumber(): int|null
    {
        return $this->getModuleIntOrNull(__FUNCTION__);
    }


    /**
     * Get the artist name.
     */
    public function getArtist(): string|null
    {
        return $this->getModuleStringOrNull(__FUNCTION__);
    }


    /**
     * Get the band (album artist)
     */
    public function getBand(): string|null
    {
        return $this->getModuleStringOrNull(__FUNCTION__);
    }


    /**
     * Get the album name.
     */
    public function getAlbum(): string|null
    {
        return $this->getModuleStringOrNull(__FUNCTION__);
    }

    public function getGenre(): string|null
    {
        return $this->getModuleStringOrNull(__FUNCTION__);
    }


    /**
     * Get the release year.
     */
    public function getYear(): int|null
    {
        return $this->getModuleIntOrNull(__FUNCTION__);
    }

    /**
     * Set a value using all active modules.
     *
     * @param string $method The method name to call on the modules
     * @param mixed $value The value to pass to the module method
     *
     * @return $this
     */
    private function setModuleValue(string $method, mixed $value): static
    {
        foreach ($this->modules as $module) {
            $module->open($this->file);
            $module->$method($value);
        }

        return $this;
    }


    /**
     * Set the track title.
     *
     * @param string $title The title name
     *
     * @return $this
     */
    public function setTitle(string $title): static
    {
        return $this->setModuleValue(__FUNCTION__, $title);
    }

    /**
     * Set the track number.
     *
     * @param int $track The track number
     *
     * @return $this
     */
    public function setTrackNumber(int $track): static
    {
        return $this->setModuleValue(__FUNCTION__, $track);
    }


    /**
     * Set the artist name.
     *
     * @param string $artist The artist name
     *
     * @return $this
     */
    public function setArtist(string $artist): static
    {
        return $this->setModuleValue(__FUNCTION__, $artist);
    }

    /**
     * Set the album name.
     *
     * @param string $album The album name
     *
     * @return $this
     */
    public function setAlbum(string $album): static
    {
        return $this->setModuleValue(__FUNCTION__, $album);
    }

    /**
     * Set the release year.
     *
     * @param int $year The release year
     *
     * @return $this
     */
    public function setYear(int $year): static
    {
        return $this->setModuleValue(__FUNCTION__, $year);
    }


    /**
     * Save the changes currently pending.
     *
     * @return $this
     */
    public function save(): static
    {
        foreach ($this->getModules() as $module) {
            $module->open($this->file);
            $module->save();
        }

        return $this;
    }
}
