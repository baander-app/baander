<?php

namespace App\Support\Logger;

use Illuminate\Support\Facades\Log;

class StdOutLogger
{
    private const string LOG_CHANNEL = 'stdout';
    private const string LOG_CHANNEL_ERROR = 'stderr';

    /**
     * @param string|\Stringable $message
     * @param array $context
     * @return void
     */
    public function info($message, array $context = []): void
    {
        Log::channel(self::LOG_CHANNEL)->info($message, $context);
    }

    /**
     * @param string|\Stringable $message
     * @param array $context
     * @return void
     */
    public function warning($message, array $context = []): void
    {
        Log::channel(self::LOG_CHANNEL_ERROR)->warning($message, $context);
    }

    /**
     * @param string|\Stringable $message
     * @param array $context
     * @return void
     */
    public function debug($message, array $context = []): void
    {
        Log::channel(self::LOG_CHANNEL)->debug($message, $context);
    }

    /**
     * @param string|\Stringable $message
     * @param array $context
     * @return void
     */
    public function error($message, array $context = []): void
    {
        Log::channel(self::LOG_CHANNEL_ERROR)->error($message, $context);
    }
}
