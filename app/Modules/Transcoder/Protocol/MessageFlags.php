<?php

namespace App\Modules\Transcoder\Protocol;

class MessageFlags
{
    private const int HAS_HEADERS = 0x01;
    private const int HAS_BODY = 0x02;
    private const int BINARY_BODY = 0x04;

    private function __construct(
        private readonly int $value,
    )
    {
    }

    public static function none(): self
    {
        return new self(0);
    }

    public static function fromInt(int $value): self
    {
        return new self($value);
    }

    public static function withHeaders(bool $has = true): self
    {
        return new self($has ? self::HAS_HEADERS : 0);
    }

    public static function withBody(bool $has = true, bool $binary = false): self
    {
        $value = $has ? self::HAS_BODY : 0;
        if ($has && $binary) {
            $value |= self::BINARY_BODY;
        }
        return new self($value);
    }

    public static function headersAndBody(bool $binaryBody = false): self
    {
        $value = self::HAS_HEADERS | self::HAS_BODY;
        if ($binaryBody) {
            $value |= self::BINARY_BODY;
        }
        return new self($value);
    }

    public function toInt(): int
    {
        return $this->value;
    }

    public function or(self $other): self
    {
        return new self($this->value | $other->value);
    }

    /**
     * Get flags as string for debugging
     */
    public function toString(): string
    {
        $parts = [];
        if ($this->hasHeaders()) {
            $parts[] = 'HEADERS';
        }
        if ($this->hasBody()) {
            $parts[] = 'BODY';
        }
        if ($this->isBinaryBody()) {
            $parts[] = 'BINARY';
        }
        return empty($parts) ? 'NONE' : implode('|', $parts);
    }

    public function hasHeaders(): bool
    {
        return ($this->value & self::HAS_HEADERS) !== 0;
    }

    public function hasBody(): bool
    {
        return ($this->value & self::HAS_BODY) !== 0;
    }

    public function isBinaryBody(): bool
    {
        return ($this->value & self::BINARY_BODY) !== 0;
    }
}
