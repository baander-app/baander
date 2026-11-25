<?php

declare(strict_types=1);

namespace Baander\DevDependencies\JavaScript;

use Baander\Configuration\ToolingConfiguration;
use Baander\Process\Execution\EngineExecutor;
use Baander\Process\Execution\ExecutionContext;

final class Command
{
    /**
     * @param list<string> $arguments
     * @param array<string,string> $environment
     */
    public static function main(array $arguments, string $root, array $environment): int
    {
        $mode = 'local-only';
        $json = false;
        try {
            for ($i = 0; $i < count($arguments); $i++) {
                if (in_array($arguments[$i], ['--local-only', '--online', '--advisories-only'], true)) {
                    $mode = substr($arguments[$i], 2);
                } elseif ($arguments[$i] === '--format' && ($arguments[$i + 1] ?? null) === 'json') {
                    $json = true;
                    $i++;
                } else {
                    throw new \InvalidArgumentException('Usage: check-js-dependencies.php [--local-only|--online|--advisories-only] [--format json]');
                }
            }
            $configuration = ToolingConfiguration::forRepository($root, $environment);
            $executor = new EngineExecutor(ExecutionContext::bounded($configuration->budgets->seconds('dependencies'), $environment, $root));
            try {
                $check = new Check($executor, $configuration->budgets->seconds('process'), $environment);
                $report = $mode === 'advisories-only' ? $check->advisories($root) : $check->run($root, $mode === 'local-only');
                fwrite(STDOUT, $report->render($json));
                return $report->exitCode();
            } finally {
                $executor->close();
            }
        } catch (\Throwable $error) {
            $report = new Report($mode === 'local-only', $mode === 'advisories-only');
            $report->add('gap', 'repository', 'command', $error->getMessage());
            fwrite(STDOUT, $report->render($json));
            return 2;
        }
    }
}
