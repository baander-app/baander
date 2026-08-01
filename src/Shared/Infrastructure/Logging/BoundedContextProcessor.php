<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

final class BoundedContextProcessor implements ProcessorInterface
{
    private const CHANNEL_TO_CONTEXT = [
        'auth' => 'auth',
        'catalog' => 'catalog',
        'library' => 'library',
        'media' => 'media',
        'metadata' => 'metadata',
        'playlist' => 'playlist',
        'recommendation' => 'recommendation',
        'activity' => 'activity',
        'lyrics' => 'lyrics',
        'filesystem' => 'filesystem',
        'security' => 'auth',
        'messenger' => 'shared',
        'deprecation' => 'shared',
        'app' => 'shared',
    ];

    public function __invoke(LogRecord $record): LogRecord
    {
        $channel = $record->channel;

        // Direct match
        if (isset(self::CHANNEL_TO_CONTEXT[$channel])) {
            $record->extra['bounded_context'] = self::CHANNEL_TO_CONTEXT[$channel];

            return $record;
        }

        // Channel is "app.{context}" — extract the context part
        if (str_starts_with($channel, 'app.')) {
            $part = substr($channel, 4);
            if (isset(self::CHANNEL_TO_CONTEXT[$part])) {
                $record->extra['bounded_context'] = self::CHANNEL_TO_CONTEXT[$part];

                return $record;
            }
        }

        return $record;
    }
}
