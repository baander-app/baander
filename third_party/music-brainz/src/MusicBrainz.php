<?php

declare(strict_types=1);

namespace MusicBrainz;

use MusicBrainz\HttpAdapter\AbstractHttpAdapter;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use function is_null;

/**
 * The library's main class
 */
class MusicBrainz
{
    /**
     * The Http adapter used to make requests
     *
     * @var AbstractHttpAdapter
     */
    private AbstractHttpAdapter $adapter;

    /**
     * A set of configuration.
     *
     * @var Config
     */
    private Config $config;

    /**
     * A logger
     *
     * @var LoggerInterface
     */
    private static LoggerInterface $logger;

    /**
     * Constructs the MusicBrainz API client.
     *
     * @param AbstractHttpAdapter  $adapter The Http adapter used to make requests
     * @param null|LoggerInterface $logger  A logger
     * @param null|Config          $config  A set of configuration
     */
    public function __construct(AbstractHttpAdapter $adapter, LoggerInterface $logger = null, Config $config = null)
    {
        $this->adapter = $adapter;
        $this->config  = $config ?? new Config();
        self::setLogger($logger);
    }

    /**
     * Returns the API.
     *
     * @return Api
     */
    public function api(): Api
    {
        return new Api($this->adapter, $this->config());
    }

    /**
     * Returns the configuration.
     *
     * @return Config
     */
    public function config(): Config
    {
        return $this->config;
    }

    /**
     * Returns the logger.
     *
     * @return LoggerInterface
     */
    public static function log(): LoggerInterface
    {
        return self::$logger;
    }

    /**
     * Sets a given logger statically and returns it. Creates a new null object logger, sets it statically and returns
     * it, if no logger was given.
     *
     * @param null|LoggerInterface $logger A logger
     *
     * @return LoggerInterface
     */
    private static function setLogger(LoggerInterface $logger = null): LoggerInterface
    {
        return self::$logger = is_null($logger) ? new NullLogger() : $logger;
    }
}
