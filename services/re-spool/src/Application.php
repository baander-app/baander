<?php

namespace Baander\ReSpool;

use Psr\Log\LoggerInterface;

class Application
{
    public function __construct(
        private readonly LoggerInterface $logger,

    )
    {
    }

    public function start()
    {
        $this->logger->info('Starting app');
    }
}