<?php

namespace App\Modules\Logging;

use App\Modules\Logging\Attributes\LogChannel;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionProperty;

class LoggerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->resolving(function ($object, $app) {
            $this->injectLoggers($object);
        });
    }

    private function injectLoggers($object): void
    {
        if (!is_object($object)) {
            return;
        }

        $reflection = new ReflectionClass($object);

        foreach ($reflection->getProperties() as $property) {
            if (!$this->shouldInjectLogger($property)) {
                continue;
            }

            $channelAttribute = $this->getLogChannelAttribute($property);
            if (!$channelAttribute) {
                continue;
            }

            $logger = Log::channel($channelAttribute->channel->value);

            $property->setAccessible(true);
            $property->setValue($object, $logger);
        }
    }

    private function shouldInjectLogger(ReflectionProperty $property): bool
    {
        $type = $property->getType();

        return $type &&
            $type instanceof \ReflectionNamedType &&
            $type->getName() === LoggerInterface::class;
    }

    private function getLogChannelAttribute(ReflectionProperty $property): ?LogChannel
    {
        $attributes = $property->getAttributes(LogChannel::class);

        return $attributes ? $attributes[0]->newInstance() : null;
    }
}
