<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Swoole\Control;

use App\Shared\Application\Port\ServerControlException;
use LogicException;

final readonly class ServerControlOperationRegistry
{
    /** @var array<string, ServerControlOperation> */
    private array $operations;

    /** @param iterable<ServerControlOperation> $operations */
    public function __construct(iterable $operations)
    {
        $byName = [];
        foreach ($operations as $operation) {
            $name = $operation->name();
            if (isset($byName[$name])) {
                throw new LogicException(sprintf('Server control operation "%s" is registered twice.', $name));
            }
            $byName[$name] = $operation;
        }
        $this->operations = $byName;
    }

    /** @throws ServerControlException when no operation has this name */
    public function get(string $name): ServerControlOperation
    {
        return $this->operations[$name]
            ?? throw new ServerControlException(sprintf('unknown server control operation "%s"', $name));
    }
}
