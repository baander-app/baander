<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final class UnsafeDestinationException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('The outbound destination is not permitted.');
    }
}
