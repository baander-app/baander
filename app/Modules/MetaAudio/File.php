<?php

namespace App\Modules\MetaAudio;

use App\Modules\MetaAudio\Exception\{ReadException, WriteException};
use SplFileObject;
use const SEEK_CUR;
use const SEEK_END;

class File implements FileInterface
{
    /** @var int An internal value to represent we should stop looping */
    private const int CALLBACK_STOP = 408;

    /** @var int The amount of data to read from files in one go */
    private const int BUFFER_SIZE = 32768;

    /** @var SplFileObject $file The underlying file instance */
    public SplFileObject $file;


    /**
     * Create a new file object.
     *
     * @param string $filename The filename to open
     */
    public function __construct(string $filename)
    {
        $this->file = new SplFileObject($filename, 'r+');
    }


    /**
     * @inheritdoc
     */
    public function getFullPath(): string
    {
        return $this->file->getPath() . DIRECTORY_SEPARATOR . $this->file->getFilename();
    }


    /**
     * @inheritdoc
     */
    public function rewind(): void
    {
        $this->file->fseek(0);
    }


    /**
     * @inheritdoc
     */
    public function seek(int $offset): void
    {
        $this->file->fseek($offset, SEEK_CUR);
    }


    /**
     * @inheritdoc
     */
    public function seekFromStart(int $offset): void
    {
        $this->file->fseek($offset);
    }


    /**
     * @inheritdoc
     */
    public function seekFromEnd(int $offset): void
    {
        $this->file->fseek($offset, SEEK_END);
    }


    /**
     * @inheritdoc
     */
    public function eof(): bool
    {
        return $this->file->eof();
    }


    /**
     * @inheritdoc
     */
    public function read(int $bytes): string
    {
        $data = $this->file->fread($bytes);

        if (!$data) {
            throw new ReadException('Unable to read from the file');
        }

        return $data;
    }


    /**
     * @inheritdoc
     */
    public function readAll(): string
    {
        $contents = '';

        $this->readNextCallback(function ($data) use (&$contents) {
            $contents .= $data;
        });

        return $contents;
    }


    /**
     * @inheritdoc
     */
    public function getCurrentPosition(): int
    {
        $position = $this->file->ftell();

        if ($position === false) {
            throw new ReadException('Unable to get the current position in the file');
        }

        return $position;
    }


    /**
     * @inheritdoc
     */
    public function truncate(int $bytes = 0): void
    {
        $result = $this->file->ftruncate($bytes);

        if (!$result) {
            throw new WriteException('Unable to truncate the file');
        }
    }


    /**
     * @inheritdoc
     */
    public function write(string $data): void
    {
        $result = $this->file->fwrite($data);

        if (!$result) {
            throw new WriteException('Unable to write to the file');
        }
    }


    /**
     * @inheritdoc
     */
    public function readNextCallback(callable $func): void
    {
        while (!$this->eof()) {
            $data = $this->read(self::BUFFER_SIZE);
            $result = $func($data);

            # If the callback has finished reading and isn't interested in the rest then stop here
            if ($result === self::CALLBACK_STOP) {
                break;
            }
        }
    }


    /**
     * @inheritdoc
     */
    public function readPreviousCallback(callable $func): void
    {
        while ($this->getCurrentPosition() > 0) {
            $length = self::BUFFER_SIZE;
            if ($this->getCurrentPosition() < $length) {
                $length = $this->getCurrentPosition();
            }

            # Position back to the start of the chunk we want to read
            $this->seek($length * -1);

            # Read the chunk
            $data = $this->read($length);

            # Position back to the start of the chunk we've just read
            $this->seek($length * -1);

            $func($data);
        }
    }


    /**
     * @inheritdoc
     */
    public function getNextPosition(string $string): ?int
    {
        $pos = null;

        $start = $this->getCurrentPosition();

        $this->readNextCallback(function (string $data) use (&$pos, $string, $start): ?int {

            $position = strpos($data, $string);
            if ($position === false) {
                if (!$this->eof()) {
                    $length = strlen($string) - 1;
                    $this->seek($length * -1);
                }
                return null;
            }

            # Calculate the position of the string as an offset of the starting position
            $pos = $this->getCurrentPosition() - $start - strlen($data) + $position;

            # Tell the readNextCallback() that we're done reading
            return self::CALLBACK_STOP;
        });

        # Position back to where we were before finding the string
        $this->seekFromStart($start);

        return $pos;
    }


    /**
     * @inheritdoc
     */
    public function getPreviousPosition(string $string): ?int
    {
        $pos = null;

        $start = $this->getCurrentPosition();

        $this->readPreviousCallback(function ($data) use (&$pos, $string, $start): ?int {

            $position = strrpos($data, $string);
            if ($position === false) {
                if ($this->getCurrentPosition() > 0) {
                    $length = strlen($string) - 1;
                    $this->seek($length);
                }
                return null;
            }

            # Calculate the position of the string as an offset of the starting position
            $pos = $this->getCurrentPosition() - $start + $position;

            # Tell the readNextCallback() that we're done reading
            return self::CALLBACK_STOP;
        });

        # Position back to where we were before finding the string
        $this->seekFromStart($start);

        return $pos;
    }
}
