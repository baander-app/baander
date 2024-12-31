<?php

namespace Baander\ReSpool;

use Psr\Log\LoggerInterface;

class Kernel
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }
    /**
     * Handle the shutdown process (e.g. logging errors).
     */
    public function registerShutdownFunction(): void
    {
        register_shutdown_function(function () {
            if ($error = error_get_last()) {
                $this->logger->error(
                    $error['message'],
                    [
                        'type' => $error['type'],
                        'file' => $error['file'],
                        'line' => $error['line'],
                    ]
                );
            }
        });
    }
}