<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Adds the main request's URL, client address, method, server name and referrer to every
 * record, as Symfony's WebProcessor does, with the URL and the referrer cut to 2,048 bytes
 * at a character boundary. It holds the request it saw, so it is tagged kernel.reset: the
 * Swoole bundle gives each coroutine its own instance, and two interleaved requests each log
 * their own URL. The reset clears the fields when the coroutine releases the instance.
 */
final class BoundedWebProcessor implements ProcessorInterface, EventSubscriberInterface, ResetInterface
{
    public const int MAXIMUM_BYTES = 2048;

    /** @var array<string, string|null> */
    private array $fields = [];

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 4096]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        $this->fields = [
            'url' => self::bounded($request->server->get('REQUEST_URI')),
            'ip' => $request->getClientIp(),
            'http_method' => $request->getMethod(),
            'server' => self::bounded($request->server->get('SERVER_NAME')),
            'referrer' => self::bounded($request->headers->get('referer')),
        ];
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        if ($this->fields !== []) {
            $record->extra = [...$record->extra, ...$this->fields];
        }

        return $record;
    }

    public function reset(): void
    {
        $this->fields = [];
    }

    private static function bounded(mixed $value): ?string
    {
        return is_string($value) ? mb_strcut($value, 0, self::MAXIMUM_BYTES, 'UTF-8') : null;
    }
}
