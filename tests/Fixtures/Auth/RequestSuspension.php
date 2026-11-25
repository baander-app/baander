<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Auth;

use RuntimeException;
use Swoole\Coroutine\Channel;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Suspends a marked request after the firewall has authenticated it and before its controller
 * runs, until the test resumes it.
 *
 * A web worker switches between requests only where one waits in a coroutine, and no route waits
 * between its authentication and its first read of the user, so a test has to place the wait
 * itself. The wait is a pop on a channel, which suspends the coroutine as waiting for I/O does.
 */
final class RequestSuspension
{
    /** The request attribute that marks the request to suspend. */
    public const string ATTRIBUTE = '_suspend_after_firewall';

    /** kernel.request priority below the firewall (8) and the listeners at 7 that read the token. */
    public const int PRIORITY = 0;

    private const float WAIT_SECONDS = 10.0;

    private readonly Channel $suspended;
    private readonly Channel $resumed;

    public function __construct()
    {
        $this->suspended = new Channel(1);
        $this->resumed = new Channel(1);
    }

    public function __invoke(RequestEvent $event): void
    {
        if ($event->getRequest()->attributes->get(self::ATTRIBUTE) !== true) {
            return;
        }
        $this->suspended->push(true);
        if ($this->resumed->pop(self::WAIT_SECONDS) !== true) {
            throw new RuntimeException('The suspended request was not resumed.');
        }
    }

    /** Returns once a marked request is suspended. */
    public function awaitSuspended(): void
    {
        if ($this->suspended->pop(self::WAIT_SECONDS) !== true) {
            throw new RuntimeException('No request was suspended.');
        }
    }

    public function resume(): void
    {
        $this->resumed->push(true);
    }
}
