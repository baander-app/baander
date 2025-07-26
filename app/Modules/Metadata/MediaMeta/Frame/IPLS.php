<?php

namespace App\Modules\Metadata\MediaMeta\Frame;

use App\Modules\Metadata\MediaMeta\Encoding;

/**
 * IPLS frame - Involved people list.
 *
 * The 'Involved people list' frame is intended to store the names of those involved in the
 * creation of the audio file, and their involvement. The frame consists of pairs of names and
 * involvements, separated by null bytes.
 */
class IPLS extends Frame
{
    /**
     * Constructs the IPLS frame with given parameters.
     */
    public function __construct(
        protected array $people = [],
        int             $encoding = Encoding::UTF8,
    )
    {
        parent::__construct('IPLS', $encoding);
    }

    /**
     * Returns the involved people list.
     */
    public function getPeople(): array
    {
        return $this->people;
    }

    /**
     * Sets the involved people list.
     */
    public function setPeople(array $people): self
    {
        $this->people = $people;
        return $this;
    }

    /**
     * Adds a person to the involved people list.
     */
    public function addPerson(string $name, string $involvement): self
    {
        $this->people[] = ['name' => $name, 'involvement' => $involvement];
        return $this;
    }

    /**
     * Parses the frame data.
     */
    public function parse(string $frameData): self
    {
        // The first byte is text encoding
        $encoding = ord($frameData[0]);
        $data = substr($frameData, 1);

        // Determine the null terminator based on encoding
        $isUnicode = in_array($encoding, [Encoding::UTF16, Encoding::UTF16BE]);
        $nullTerminator = $isUnicode ? "\0\0" : "\0";

        // Split the data into pairs of names and involvements
        $this->people = [];
        $parts = explode($nullTerminator, $data);

        // Process pairs (name, involvement)
        for ($i = 0; $i < count($parts) - 1; $i += 2) {
            $name = $parts[$i];
            $involvement = $parts[$i + 1] ?? '';

            // Process name and involvement based on encoding
            $name = match ($encoding) {
                Encoding::UTF16, Encoding::UTF16BE => mb_convert_encoding($name, 'UTF-8', 'UTF-16'),
                Encoding::UTF16LE => mb_convert_encoding($name, 'UTF-8', 'UTF-16LE'),
                Encoding::UTF8 => $name,
                default => mb_convert_encoding($name, 'UTF-8', 'ISO-8859-1')
            };

            $involvement = match ($encoding) {
                Encoding::UTF16, Encoding::UTF16BE => mb_convert_encoding($involvement, 'UTF-8', 'UTF-16'),
                Encoding::UTF16LE => mb_convert_encoding($involvement, 'UTF-8', 'UTF-16LE'),
                Encoding::UTF8 => $involvement,
                default => mb_convert_encoding($involvement, 'UTF-8', 'ISO-8859-1')
            };

            $this->people[] = ['name' => $name, 'involvement' => $involvement];
        }

        return $this;
    }

    /**
     * Converts the frame to binary data.
     */
    public function toBytes(): string
    {
        // Determine if we're using Unicode
        $isUnicode = in_array($this->encoding, [Encoding::UTF16, Encoding::UTF16BE]);
        $nullTerminator = $isUnicode ? "\0\0" : "\0";

        // Start with the encoding byte
        $data = chr($this->encoding);

        // Add each person (name and involvement, separated by null bytes)
        foreach ($this->people as $person) {
            // Convert name to the specified encoding
            $encodedName = match ($this->encoding) {
                Encoding::UTF16, Encoding::UTF16BE => mb_convert_encoding($person['name'], 'UTF-16', 'UTF-8'),
                Encoding::UTF16LE => mb_convert_encoding($person['name'], 'UTF-16LE', 'UTF-8'),
                Encoding::UTF8 => $person['name'],
                default => mb_convert_encoding($person['name'], 'ISO-8859-1', 'UTF-8')
            };

            // Convert involvement to the specified encoding
            $encodedInvolvement = match ($this->encoding) {
                Encoding::UTF16, Encoding::UTF16BE => mb_convert_encoding($person['involvement'], 'UTF-16', 'UTF-8'),
                Encoding::UTF16LE => mb_convert_encoding($person['involvement'], 'UTF-16LE', 'UTF-8'),
                Encoding::UTF8 => $person['involvement'],
                default => mb_convert_encoding($person['involvement'], 'ISO-8859-1', 'UTF-8')
            };

            // Add the name, null terminator, involvement, and null terminator
            $data .= $encodedName . $nullTerminator . $encodedInvolvement . $nullTerminator;
        }

        return $data;
    }
}
