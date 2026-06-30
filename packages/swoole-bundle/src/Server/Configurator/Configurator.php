<?php

declare(strict_types=1);

namespace SwooleBundle\SwooleBundle\Server\Configurator;

use Swoole\Server;

interface Configurator
{
    public function configure(Server $server): void;
}
