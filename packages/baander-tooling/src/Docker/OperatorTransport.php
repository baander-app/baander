<?php

declare(strict_types=1);

namespace Baander\Tooling\Docker;

final readonly class OperatorTransport
{
    public function __construct(public string $daemonHostSocketPath, public string $containerSocketPath)
    {
        foreach ([$daemonHostSocketPath, $containerSocketPath] as $path) {
            if (!str_starts_with($path, '/') || preg_match('/[\x00-\x1f\x7f]/', $path) === 1 || in_array('..', explode('/', $path), true)) {
                throw new \InvalidArgumentException('Operator socket mappings require absolute paths without traversal.');
            }
        }
    }

    public function target(Target $parent, string $executable): Target
    {
        return new Target($executable, 'unix://' . $this->containerSocketPath, $parent->daemonId, $parent->originalContext, null);
    }

    /** @return array{daemonHostSocketPath:string,containerSocketPath:string} */
    public function toArray(): array
    {
        return ['daemonHostSocketPath' => $this->daemonHostSocketPath, 'containerSocketPath' => $this->containerSocketPath];
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        if (count($data) !== 2 || !is_string($data['daemonHostSocketPath'] ?? null) || !is_string($data['containerSocketPath'] ?? null)) {
            throw new \InvalidArgumentException('Invalid operator socket mapping.');
        }
        return new self($data['daemonHostSocketPath'], $data['containerSocketPath']);
    }
}
