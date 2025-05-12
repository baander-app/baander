<?php

namespace App\Modules\Filesystem;

class MimeExtension
{
    public const array EXT_MIME_MAP = [
        // images
        'png' => 'image/png',
        'jpe' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'jpg' => 'image/jpeg',
        'gif' => 'image/gif',
        'bmp' => 'image/bmp',
        'ico' => 'image/vnd.microsoft.icon',
        'tiff' => 'image/tiff',
        'tif' => 'image/tiff',
        'svg' => 'image/svg+xml',
        'svgz' => 'image/svg+xml',

        // audio
        'mp3' => 'audio/mpeg',
        'wav' => 'audio/x-wav',
        'ogg' => 'audio/ogg',
        'm4a' => 'audio/x-m4a',
        'flac' => 'audio/x-flac',
        'aac' => 'audio/x-aac',
        'wma' => 'audio/x-ms-wma',
        'mid' => 'audio/midi',
        'midi' => 'audio/midi',
        'kar' => 'audio/midi',
        'ra' => 'audio/x-realaudio',
        'ram' => 'audio/x-pn-realaudio',
        'rm' => 'audio/x-pn-realaudio',
        'rpm' => 'audio/x-pn-realaudio-plugin',
        'wax' => 'audio/x-ms-wax',
        'au' => 'audio/basic',
        'snd' => 'audio/basic',
        'aif' => 'audio/x-aiff',
        'm3u' => 'audio/x-mpegurl',

        // video
        'qt' => 'video/quicktime',
        'mov' => 'video/quicktime',
    ];

    public static function getExtension(string $mime): string|null
    {
        return self::EXT_MIME_MAP[$mime] ?? null;
    }

    public static function getMime(string $extension): string|null
    {
        return array_search($extension, self::EXT_MIME_MAP, true) ?? null;
    }
}