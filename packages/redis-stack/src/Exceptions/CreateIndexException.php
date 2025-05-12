<?php

namespace Baander\RedisStack\Exceptions;

class CreateIndexException extends Exception
{
    public static function unknownException(?string $message = null)
    {
        throw new static($message ?? 'Unknown exception');
    }
}