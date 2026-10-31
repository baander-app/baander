<?php

declare(strict_types=1);

namespace Baander\Tooling\Website;

use Baander\Tooling\Process\EngineExecutor;
use Baander\Tooling\Process\ExecutionContext;
use Baander\Tooling\Process\ProcessSpec;

final class Command
{
    /** @param list<string> $arguments
     * @param array<string,string> $environment
     */
    public static function main(array $arguments, string $root, array $environment): int
    {
        if ($arguments !== []) {
            throw new \InvalidArgumentException('Usage: php scripts/build-website.php');
        }
        $executor = new EngineExecutor(new ExecutionContext($environment));
        $revision = $executor->run(new ProcessSpec(['git', 'rev-parse', 'HEAD'], cwd: $root))->stdout |> trim(...);
        $pages = new WebsiteBuilder($root, 'https://github.com/baander-app/baander/tree/' . $revision)->build();
        fwrite(STDOUT, 'Built ' . count($pages) . ' manual pages in var/website.' . "\n");
        return 0;
    }
}
