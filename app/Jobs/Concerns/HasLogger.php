<?php

namespace App\Jobs\Concerns;

use App\Modules\Logging\Attributes\LogChannel;
use App\Modules\Logging\StructuredLogger;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionProperty;

trait HasLogger
{
    private array $loggerCache = [];

    protected function getLogger(string $propertyName = 'logger'): LoggerInterface
    {
        // Return cached logger if available
        if (isset($this->loggerCache[$propertyName])) {
            return $this->loggerCache[$propertyName];
        }

        $reflection = new ReflectionClass($this);

        // Try to find the property
        if (!$reflection->hasProperty($propertyName)) {
            throw new \InvalidArgumentException("Logger property '{$propertyName}' not found in " . get_class($this));
        }

        $property = $reflection->getProperty($propertyName);

        if (!$this->shouldInitializeLogger($property)) {
            throw new \InvalidArgumentException("Property '{$propertyName}' is not a valid logger property");
        }

        $channelAttribute = $this->getLogChannelAttribute($property);
        if (!$channelAttribute) {
            throw new \InvalidArgumentException("Property '{$propertyName}' does not have a LogChannel attribute");
        }

        $baseLogger = Log::channel($channelAttribute->channel->value);

        // Create structured logger with enhanced context
        $structuredLogger = new StructuredLogger(
            $baseLogger,
            $reflection->getName(),
            $channelAttribute->defaultContext
        );

        // Cache the logger
        $this->loggerCache[$propertyName] = $structuredLogger;

        return $structuredLogger;
    }

    private function shouldInitializeLogger(ReflectionProperty $property): bool
    {
        $type = $property->getType();

        return $type instanceof \ReflectionNamedType &&
            $type->getName() === LoggerInterface::class;
    }

    private function getLogChannelAttribute(ReflectionProperty $property): ?LogChannel
    {
        $attributes = $property->getAttributes(LogChannel::class);

        return $attributes ? $attributes[0]->newInstance() : null;
    }
}