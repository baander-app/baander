<?php

declare(strict_types=1);

namespace Baander\Tooling\JavaScriptDependencies;

use Baander\Tooling\Configuration\ToolingConfiguration;
use Baander\Tooling\Process\EngineExecutor;
use Baander\Tooling\Process\ExecutionContext;

final class Command
{
    /**
     * @param list<string> $arguments
     * @param array<string,string> $environment
     */
    public static function main(array $arguments, string $root, array $environment): int
    {
        $localOnly = true;
        $json = false;
        try {
            for ($i = 0; $i < count($arguments); $i++) {
                if ($arguments[$i] === '--online') {
                    $localOnly = false;
                } elseif ($arguments[$i] === '--local-only') {
                    $localOnly = true;
                } elseif ($arguments[$i] === '--format' && ($arguments[$i + 1] ?? null) === 'json') {
                    $json = true;
                    $i++;
                } else {
                    throw new \InvalidArgumentException('Usage: check-js-dependencies.php [--local-only|--online] [--format json]');
                }
            }
            $configuration = ToolingConfiguration::forRepository($root, $environment);
            $executor = new EngineExecutor(ExecutionContext::bounded($configuration->budgets->seconds('dependencies'), $environment, $root));
            try {
                $report = (new Check($executor, $configuration->budgets->seconds('process'), $environment))->run($root, $localOnly);
                fwrite(STDOUT, $report->render($json));
                return $report->exitCode();
            } finally {
                $executor->close();
            }
        } catch (\Throwable $error) {
            $report = new Report($localOnly);
            $report->add('gap', 'repository', 'command', $error->getMessage());
            fwrite(STDOUT, $report->render($json));
            return 2;
        }
    }
}
