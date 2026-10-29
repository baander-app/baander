<?php

declare(strict_types=1);

namespace Baander\Tooling\Configuration;

final readonly class ApplicationLayout
{
    private function __construct(public int $version, public string $root) {}

    public static function load(?string $path = null): self
    {
        return self::fromArray(JsonObject::read($path ?? dirname(__DIR__, 2) . '/resources/application-layout.json'));
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        JsonObject::keys($data, ['version', 'root'], 'application layout');
        if (($data['version'] ?? null) !== 1) {
            throw new \InvalidArgumentException('Unsupported application layout version.');
        }
        $root = $data['root'] ?? null;
        if (!is_string($root) || !str_starts_with($root, '/') || $root === '/' || str_ends_with($root, '/') || !self::safeSegments(substr($root, 1))) {
            throw new \InvalidArgumentException('Application root must be a canonical absolute directory.');
        }
        return new self(1, $root);
    }

    public function path(string $relative): string
    {
        if (!self::safeSegments($relative)) {
            throw new \InvalidArgumentException('Application path must be a safe relative path.');
        }
        return $this->root . '/' . $relative;
    }

    private static function safeSegments(string $path): bool
    {
        if (preg_match('~^[a-zA-Z0-9_./-]+$~D', $path) !== 1) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if (in_array($segment, ['', '.', '..'], true)) {
                return false;
            }
        }
        return true;
    }

    /** @return array{version:int,root:string} */
    public function toArray(): array
    {
        return ['version' => $this->version, 'root' => $this->root];
    }
}
