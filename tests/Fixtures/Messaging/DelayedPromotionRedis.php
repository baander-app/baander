<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Messaging;

/** Coordinates independent clients after selecting a delayed candidate. */
final class DelayedPromotionRedis extends \Redis
{
    public ?\Closure $afterSelection = null;
    public int $selections = 0;

    public function rawCommand(string $command, mixed ...$args): mixed
    {
        $result = parent::rawCommand($command, ...$args);
        if (strtoupper($command) === 'ZRANGEBYSCORE' && is_array($result) && $result !== []) {
            ++$this->selections;
            if ($this->afterSelection !== null) {
                ($this->afterSelection)($result[0]);
            }
        }
        return $result;
    }
}
