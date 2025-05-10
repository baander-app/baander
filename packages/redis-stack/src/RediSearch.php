<?php

namespace Baander\RedisStack;

class RediSearch
{
    public static function getSupportedLangs()
    {
        return [
            'danish',
            'dutch',
            'english',
            'finnish',
            'french',
            'german',
            'hungarian',
            'italian',
            'norwegian',
            'portuguese',
            'romanian',
            'russian',
            'spanish',
            'swedish',
            'tamil',
            'turkish',
        ];
    }

    public static function isLanguageSupported($lang): bool
    {
        return in_array($lang, self::getSupportedLangs());

    }
}