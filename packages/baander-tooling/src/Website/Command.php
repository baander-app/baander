<?php

declare(strict_types=1);

namespace Baander\Tooling\Website;

use Baander\Tooling\Configuration\ToolingConfiguration;
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
        $configuration = ToolingConfiguration::forRepository($root, $environment);
        $executor = new EngineExecutor(ExecutionContext::bounded($configuration->budgets->seconds('process'), $environment, $root));
        try {
            $revision = $executor->run(new ProcessSpec(['git', 'rev-parse', 'HEAD']))->stdout |> trim(...);
            $pages = new WebsiteBuilder($root, 'https://github.com/baander-app/baander/tree/' . $revision)->build(
                static function (string $staging) use ($executor, $root): void {
                    Publication::assets($root, $staging);
                    $executor->run(new ProcessSpec(
                        ['corepack', 'yarn', 'build', $staging . '/assets/site/search.js'],
                        cwd: $root . '/ui/site',
                    ));
                    $executor->run(new ProcessSpec(
                        ['corepack', 'yarn', 'pagefind', '--site', $staging, '--output-subdir', 'pagefind'],
                        cwd: $root . '/ui/site',
                    ));
                    Publication::package($staging);
                },
            );
            fwrite(STDOUT, 'Built ' . count($pages) . ' manual pages in var/website.' . "\n");
            return 0;
        } finally {
            $executor->close();
        }
    }
}
