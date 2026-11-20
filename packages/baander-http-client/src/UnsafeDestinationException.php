<?php

declare(strict_types=1);

namespace Baander\HttpClient;

final class UnsafeDestinationException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('The outbound destination is not permitted.');
    }
}
