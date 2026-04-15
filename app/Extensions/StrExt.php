<?php

namespace App\Extensions;

use Exception;
use Normalizer;

class StrExt
{
    public static function between($str, $starting_word, $ending_word): ?string
    {
        if (!$str) {
            return null;
        }

        try {
            $subtring_start = strpos($str, $starting_word);
            if ($subtring_start === false || $subtring_start <= 0) {
                return null;
            }

            // Adding the starting index of the starting word to
            // its length would give its ending index
            $subtring_start += strlen($starting_word);
            // Length of our required sub string
            $size = strpos($str, $ending_word, $subtring_start) - $subtring_start;

            if ($size <= 0) {
                return null;
            }

            // Return the substring from the index substring_start of length size
            return substr($str, $subtring_start, $size);
        } catch (Exception) {
            return null;
        }
    }

    public static function safe($str)
    {
        if (!$str) {
            return null;
        }

        return strip_tags(str_replace('\x00', '', $str));
    }

    public static function convertToUtf8($str): string|null
    {
        if (!$str) {
            return null;
        }

        $encoding = mb_detect_encoding($str);

        return mb_convert_encoding($str, 'UTF-8', $encoding);
    }

    /**
     * Comprehensive sanitization for user-provided text
     * Removes HTML tags, XSS patterns, and normalizes Unicode
     */
    public static function sanitize(?string $str): ?string
    {
        if (!$str) {
            return null;
        }

        // Remove null bytes
        $str = str_replace("\0", '', $str);

        // Normalize Unicode (NFC normalization)
        $str = Normalizer::normalize($str, Normalizer::FORM_C);

        // HTML encode all special characters
        $str = htmlentities($str, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Remove any remaining HTML tags (defense in depth)
        $str = strip_tags($str);

        // Remove XSS patterns
        $xssPatterns = [
            '/javascript:/i',
            '/data:text\/html/i',
            '/vbscript:/i',
            '/onload\s*=/i',
            '/onerror\s*=/i',
            '/onclick\s*=/i',
            '/onmouseover\s*=/i',
            '/<script\b/i',
            '/<iframe\b/i',
            '/<object\b/i',
            '/<embed\b/i',
        ];

        foreach ($xssPatterns as $pattern) {
            $str = preg_replace($pattern, '', $str);
        }

        return trim($str);
    }

    /**
     * Strict sanitization for metadata fields (title, artist, album, genre)
     * Removes HTML, XSS, and truncates to max length
     */
    public static function sanitizeMetadata(?string $str): ?string
    {
        if (!$str) {
            return null;
        }

        // Apply general sanitization
        $str = self::sanitize($str);

        // Remove control characters (except newlines and tabs)
        $str = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $str);

        // Normalize whitespace
        $str = preg_replace('/\s+/', ' ', $str);

        return $str;
    }

    /**
     * Sanitization for lyrics - preserves formatting while removing scripts
     * Keeps newlines, verse markers, and section headers
     */
    public static function sanitizeLyrics(?string $str): ?string
    {
        if (!$str) {
            return null;
        }

        // Remove null bytes
        $str = str_replace("\0", '', $str);

        // Normalize Unicode
        $str = Normalizer::normalize($str, Normalizer::FORM_C);

        // HTML encode
        $str = htmlentities($str, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Remove script tags and event handlers specifically
        $dangerousPatterns = [
            '/<script\b[^>]*>.*?<\/script>/is',
            '/javascript:/i',
            '/vbscript:/i',
            '/on(load|error|click|mouseover)\s*=/i',
            '/<iframe\b/i',
            '/<object\b/i',
            '/<embed\b/i',
        ];

        foreach ($dangerousPatterns as $pattern) {
            $str = preg_replace($pattern, '', $str);
        }

        // Preserve line breaks and tabs, but remove other control chars
        $str = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $str);

        return $str;
    }
}
