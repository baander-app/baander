<?php

declare(strict_types=1);

namespace SwooleBundle\SwooleBundle\Bridge\Symfony\Twig;

use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\Proxy\ContextualProxy;
use Symfony\Bridge\Twig\DataCollector\TwigDataCollector;
use Twig\Environment;
use Twig\Profiler\Profile;

/**
 * Serializes the real Profile so Symfony's Profile-only allowlist can restore it in another worker.
 *
 * @phpstan-ignore class.extendsFinalByPhpDoc
 */
final class UnwrappingTwigDataCollector extends TwigDataCollector
{
    public function __construct(
        private readonly Profile $profile,
        private readonly ?Environment $twig = null,
    ) {
        parent::__construct($profile, $twig);
    }

    public function lateCollect(): void
    {
        // The generated proxy inherits Profile::__serialize(), which reads unset private properties.
        // Keep it out of serialization while retaining it in this collector for contextual reset().
        // @phpstan-ignore instanceof.alwaysFalse
        if (!$this->profile instanceof ContextualProxy) {
            parent::lateCollect();

            return;
        }

        // @phpstan-ignore-next-line
        $collector = new TwigDataCollector($this->profile->getServicePool()->get(), $this->twig);
        $collector->lateCollect();
        $this->data = $collector->__serialize()['data'];
    }
}
