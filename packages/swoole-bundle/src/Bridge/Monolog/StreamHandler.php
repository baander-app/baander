<?php

declare(strict_types=1);

namespace SwooleBundle\SwooleBundle\Bridge\Monolog;

use Monolog\Handler\StreamHandler as OriginalStreamHandler;

final class StreamHandler extends OriginalStreamHandler
{
    use CoroutineSafeWrites;
}
