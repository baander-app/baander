<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Messaging;

use App\Shared\Application\Port\JobCancellationCheckpointInterface;

/**
 * Handles a CancellationProbe item by item, reaching a cancellation checkpoint before each,
 * as the long-running production handlers do. A test observes the items and acts between
 * them through the static hooks, which it resets afterwards.
 */
final class CancellationProbeHandler
{
    /** @var list<string> "label:item" for each item handled */
    public static array $handled = [];

    /** @var (\Closure(CancellationProbe, int): void)|null called after each item */
    public static ?\Closure $afterItem = null;

    public function __construct(
        private readonly JobCancellationCheckpointInterface $cancellation,
    ) {
    }

    public static function reset(): void
    {
        self::$handled = [];
        self::$afterItem = null;
    }

    public function __invoke(CancellationProbe $probe): int
    {
        for ($item = 1; $item <= $probe->items; ++$item) {
            $this->cancellation->check();
            self::$handled[] = $probe->label . ':' . $item;
            if (self::$afterItem !== null) {
                (self::$afterItem)($probe, $item);
            }
        }

        return $probe->items;
    }
}
