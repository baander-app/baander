<?php

declare(strict_types=1);

namespace App\Tests\Unit\Docker;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The Swoole management API (status, reload, shutdown) has no authentication
 * and listens on loopback inside the container; no compose file may publish it.
 */
final class ComposeManagementApiPortTest extends TestCase
{
    private const int MANAGEMENT_API_PORT = 9200;
    private const string ROOT = __DIR__ . '/../../..';

    /**
     * @return iterable<string, array{string}>
     */
    public static function composeFiles(): iterable
    {
        yield 'docker-compose.yml' => ['docker-compose.yml'];
        yield 'compose.override.yaml' => ['compose.override.yaml'];
    }

    #[DataProvider('composeFiles')]
    public function testComposeFilePublishesNoManagementApiPort(string $file): void
    {
        $path = self::ROOT . '/' . $file;
        self::assertFileExists($path);

        $compose = Yaml::parseFile($path, Yaml::PARSE_CUSTOM_TAGS);
        self::assertIsArray($compose);
        self::assertIsArray($compose['services'] ?? null, "{$file} must define services.");

        foreach ($compose['services'] as $service => $definition) {
            foreach ((array) ($definition['ports'] ?? []) as $port) {
                self::assertFalse(
                    $this->targetsManagementApi($port),
                    sprintf('%s service "%s" publishes the management API port: %s', $file, $service, json_encode($port)),
                );
            }
        }
    }

    private function targetsManagementApi(mixed $port): bool
    {
        if (is_array($port)) {
            $target = $port['target'] ?? null;

            return $target !== null && $this->rangeContains((string) $target);
        }

        // Short syntax: [HOST:][HOST_PORT:]CONTAINER_PORT[/PROTOCOL]; the container port is last.
        $withoutProtocol = explode('/', (string) $port, 2)[0];
        $segments = explode(':', $withoutProtocol);

        return $this->rangeContains((string) end($segments));
    }

    private function rangeContains(string $containerPort): bool
    {
        $bounds = array_map('intval', explode('-', $containerPort, 2));

        return self::MANAGEMENT_API_PORT >= $bounds[0] && self::MANAGEMENT_API_PORT <= ($bounds[1] ?? $bounds[0]);
    }
}
