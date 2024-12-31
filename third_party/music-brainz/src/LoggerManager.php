<?php

namespace MusicBrainz;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class LoggerManager
{
    /**
     * The singleton instance of the LoggerManager.
     *
     * @var LoggerManager|null
     */
    private static ?LoggerManager $instance = null;

    /**
     * The logger instance.
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * Private constructor to prevent multiple instances.
     */
    private function __construct()
    {
        $this->logger = new NullLogger();
    }

    /**
     * Gets the singleton instance of LoggerManager.
     *
     * @return LoggerManager
     */
    public static function getInstance(): LoggerManager
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Sets the logger instance.
     *
     * @param LoggerInterface $logger
     */
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * Gets the logger instance.
     *
     * @return LoggerInterface
     */
    public function getLogger(): LoggerInterface
    {
        return $this->logger;
    }
}