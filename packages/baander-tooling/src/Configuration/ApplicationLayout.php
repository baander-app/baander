<?php

declare(strict_types=1);

namespace Baander\Tooling\Configuration;

final readonly class ApplicationLayout
{
    public const string IMAGE_LABEL = 'org.baander.application-layout';

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

    /** @param array<string,mixed> $image */
    public static function fromImage(array $image): self
    {
        $metadata = $image['Config']['Labels'][self::IMAGE_LABEL] ?? null;
        if (!is_string($metadata)) {
            throw new \RuntimeException('Application image has no layout contract. Rebuild it with scripts/build-application.php.');
        }
        $data = json_decode($metadata, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid application image layout metadata.');
        }
        $layout = self::fromArray($data);
        $root = null;
        $environment = $image['Config']['Env'] ?? [];
        if (!is_array($environment)) {
            throw new \RuntimeException('Invalid application image environment.');
        }
        foreach ($environment as $entry) {
            if (is_string($entry) && str_starts_with($entry, 'APP_HOME=')) {
                $root = substr($entry, strlen('APP_HOME='));
            }
        }
        if ($root !== $layout->root || ($image['Config']['WorkingDir'] ?? null) !== $layout->root) {
            throw new \RuntimeException('Application image environment and working directory disagree with its layout contract.');
        }
        return $layout;
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
