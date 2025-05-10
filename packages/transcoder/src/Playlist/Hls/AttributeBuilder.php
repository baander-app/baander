<?php

namespace Baander\Transcoder\Playlist\Hls;

class AttributeBuilder
{
    /**
     * Build tag attributes as a formatted string.
     */
    public static function build(array $attributes): string
    {
        $formatted = [];

        foreach ($attributes as $key => $value) {
            if (is_string($value)) {
                $formatted[] = strtoupper($key) . "=\"{$value}\"";
            } else {
                $formatted[] = strtoupper($key) . '=' . $value;
            }
        }

        return implode(',', $formatted);
    }
}
