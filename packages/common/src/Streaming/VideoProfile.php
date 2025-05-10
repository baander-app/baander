<?php

namespace Baander\Common\Streaming;

class VideoProfile
{
    public function __construct(
        public int $width,
        public int $height,
        public int $bitrate,
        public ?string $codec = null,        // Codec (e.g., 'h264', 'vp9')
    ) {
    }

    /**
     * Get the aspect ratio of the video.
     *
     * @return string Aspect ratio as `width:height` format.
     */
    public function getAspectRatio(): string
    {
        $gcd = $this->greatestCommonDivisor($this->width, $this->height);
        return ($this->width / $gcd) . ':' . ($this->height / $gcd);
    }

    /**
     * Generate a descriptive string for the video profile.
     *
     * @return string Description of the video profile.
     */
    public function __toString(): string
    {
        $description = "{$this->width}x{$this->height} {$this->bitrate}bps";

        if ($this->codec) {
            $description .= ", {$this->codec} codec";
        }

        return $description;
    }

    /**
     * Compare two VideoProfiles to see if they are equivalent.
     *
     * @param VideoProfile $other The other VideoProfile to compare.
     * @return bool True if profiles are equivalent, false otherwise.
     */
    public function equals(VideoProfile $other): bool
    {
        return $this->width === $other->width &&
            $this->height === $other->height &&
            $this->bitrate === $other->bitrate &&
            $this->codec === $other->codec;
    }

    /**
     * Calculate the greatest common divisor (GCD) of two numbers.
     *
     * @param int $a First number.
     * @param int $b Second number.
     * @return int GCD of two numbers.
     */
    private function greatestCommonDivisor(int $a, int $b): int
    {
        return $b === 0 ? $a : $this->greatestCommonDivisor($b, $a % $b);
    }
}