<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Security;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Clears the security token at the start of every main request.
 *
 * An authenticator sets the token only for a request it supports, and a route outside the
 * API firewall runs no authenticator, so nothing else replaces a token an earlier request
 * left in the storage: a request without credentials would run as that request's user. The
 * web server takes the storage from a per-coroutine pool that resets it on release. A kernel
 * that handles requests outside a coroutine, as the test kernels do, keeps one storage and
 * never releases it.
 *
 * Priority 2048 is below the request span (4096) and above every listener that can answer
 * or fail a request, so each token reader runs after the token is cleared: the firewall (8),
 * the listeners behind it, and ApiLanguageListener on kernel.exception for a request that
 * failed before the firewall, such as one the router (32) could not match.
 *
 * A sub-request shares the token of its main request and is left alone.
 */
final readonly class StaleTokenListener
{
    public function __construct(private TokenStorageInterface $tokens)
    {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 2048)]
    public function onKernelRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->tokens->setToken(null);
        }
    }
}
