<?php

declare(strict_types=1);

namespace MusicBrainz;

use MusicBrainz\HttpAdapter\AbstractHttpAdapter;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class MusicBrainz
{
    public function __construct(private readonly AbstractHttpAdapter $adapter, private ?Config $config = null, ?LoggerInterface $logger = null)
    {
        $this->config = $config ?? new Config();
        LoggerManager::getInstance()->setLogger($logger ?? new NullLogger());
    }

    public function api(): Api
    {
        return new Api($this->adapter, $this->config());
    }

    public function config(): Config
    {
        return $this->config;
    }
}
