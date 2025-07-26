<?php

namespace App\Modules\Metadata\MediaMeta\Frame;

use App\Modules\Metadata\MediaMeta\Encoding;

/**
 * OWNE frame - Ownership frame.
 *
 * The 'Ownership' frame contains information about the owner of the file.
 * It includes a price paid, date of purchase, and the seller name.
 */
class OWNE extends Frame
{
    /**
     * Constructs the OWNE frame with given parameters.
     */
    public function __construct(
        protected string $price = '',
        protected string $date = '',
        protected string $seller = '',
        int              $encoding = Encoding::UTF8,
    )
    {
        parent::__construct('OWNE', $encoding);
    }

    /**
     * Returns the price paid.
     */
    public function getPrice(): string
    {
        return $this->price;
    }

    /**
     * Sets the price paid.
     */
    public function setPrice(string $price): self
    {
        $this->price = $price;
        return $this;
    }

    /**
     * Returns the date of purchase.
     */
    public function getDate(): string
    {
        return $this->date;
    }

    /**
     * Sets the date of purchase.
     */
    public function setDate(string $date): self
    {
        $this->date = $date;
        return $this;
    }

    /**
     * Returns the seller name.
     */
    public function getSeller(): string
    {
        return $this->seller;
    }

    /**
     * Sets the seller name.
     */
    public function setSeller(string $seller): self
    {
        $this->seller = $seller;
        return $this;
    }

    /**
     * Parses the frame data.
     */
    public function parse(string $frameData): self
    {
        // The first byte is text encoding
        $encoding = ord($frameData[0]);

        // Find the null terminator for the price
        $priceEnd = strpos($frameData, "\0", 1);
        if ($priceEnd === false) {
            return $this;
        }

        // Extract the price
        $this->price = substr($frameData, 1, $priceEnd - 1);

        // Extract the date (8 characters, YYYYMMDD)
        $dateStart = $priceEnd + 1;
        if ($dateStart + 8 <= strlen($frameData)) {
            $this->date = substr($frameData, $dateStart, 8);
        }

        // Extract the seller
        $sellerStart = $dateStart + 8;
        if ($sellerStart < strlen($frameData)) {
            $sellerData = substr($frameData, $sellerStart);

            // Process seller based on encoding
            $this->seller = match ($encoding) {
                Encoding::UTF16, Encoding::UTF16BE => mb_convert_encoding($sellerData, 'UTF-8', 'UTF-16'),
                Encoding::UTF16LE => mb_convert_encoding($sellerData, 'UTF-8', 'UTF-16LE'),
                Encoding::UTF8 => $sellerData,
                default => mb_convert_encoding($sellerData, 'UTF-8', 'ISO-8859-1')
            };
        }

        return $this;
    }

    /**
     * Converts the frame to binary data.
     */
    public function toBytes(): string
    {
        // Convert seller to the specified encoding
        $encodedSeller = match ($this->encoding) {
            Encoding::UTF16, Encoding::UTF16BE => mb_convert_encoding($this->seller, 'UTF-16', 'UTF-8'),
            Encoding::UTF16LE => mb_convert_encoding($this->seller, 'UTF-16LE', 'UTF-8'),
            Encoding::UTF8 => $this->seller,
            default => mb_convert_encoding($this->seller, 'ISO-8859-1', 'UTF-8')
        };

        // Start with the encoding byte
        $data = chr($this->encoding);

        // Add the price with null terminator
        $data .= $this->price . "\0";

        // Add the date (8 characters, YYYYMMDD)
        $data .= str_pad($this->date, 8, '0', STR_PAD_LEFT);

        // Add the seller
        $data .= $encodedSeller;

        return $data;
    }
}
