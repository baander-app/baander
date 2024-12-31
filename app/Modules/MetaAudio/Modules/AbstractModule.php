<?php

namespace App\Modules\MetaAudio\Modules;

use App\Modules\MetaAudio\FileInterface;

abstract class AbstractModule implements ModuleInterface
{
    /**
     * @var array|null $tags The parsed tags from the file.
     */
    private $tags;

    /**
     * @var FileInterface $file The file to read.
     */
    protected $file;


    /**
     * Load the passed file.
     *
     * @param FileInterface $file The file to read
     *
     * @return $this
     */
    public function open(FileInterface $file)
    {
        # If this file is already loaded then don't do anything
        if ($this->file) {
            $path1 = $this->file->getFullPath();
            $path2 = $file->getFullPath();
            if ($path1 === $path2) {
                return $this;
            }
        }

        $this->file = $file;
        $this->tags = null;

        return $this;
    }


    /**
     * Get all the tags from the currently loaded file.
     *
     * @return array
     */
    abstract protected function getTags();


    /**
     * Get a tag from the file.
     *
     * @param string $key The name of the tag to get
     *
     * @return mixed
     */
    protected function getTag($key)
    {
        if (!is_array($this->tags)) {
            $this->tags = $this->getTags();
        }

        return $this->tags[$key] ?? '';
    }


    /**
     * Set a tag in the file.
     *
     * @param string $key The name of the tag to set
     * @param mixed $value The value to set the tag to
     *
     * @return $this
     */
    protected function setTag($key, $value)
    {
        $old = $this->getTag($key);

        if ($old !== $value) {
            $this->tags[$key] = $value;
        }

        return $this;
    }


    /**
     * Write the specified tags to the currently loaded file.
     *
     * @param array The tags to write as key/value pairs
     *
     * @return void
     */
    abstract protected function putTags(array $tags);


    /**
     * Save the changes currently pending.
     *
     * @return $this
     */
    public function save()
    {
        $this->putTags($this->tags);
        return $this;
    }


    /**
     * Throw away any pending changes.
     *
     * @return $this
     */
    public function revert()
    {
        $this->tags = null;
        return $this;
    }

    protected function textEncodingTerminatorLookup($encoding): string
    {
        // http://www.id3.org/id3v2.4.0-structure.txt
        // Frames that allow different types of text encoding contains a text encoding description byte. Possible encodings:
        $map = [
            0   => "\x00",     // $00  ISO-8859-1. Terminated with $00.
            1   => "\x00\x00", // $01  UTF-16 encoded Unicode with BOM. All strings in the same frame SHALL have the same byteorder. Terminated with $00 00.
            2   => "\x00\x00", // $02  UTF-16BE encoded Unicode without BOM. Terminated with $00 00.
            3   => "\x00",     // $03  UTF-8 encoded Unicode. Terminated with $00.
            255 => "\x00\x00",
        ];
        return ($map[$encoding] ?? '');
    }
}
