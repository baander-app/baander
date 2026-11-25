<?php

declare(strict_types=1);

namespace Baander\DevDependencies\Php;

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
        $mode = 'online';
        $json = false;
        try {
            for ($i = 0; $i < count($arguments); $i++) {
                if (in_array($arguments[$i], ['--local-only', '--advisories-only', '--image-drift'], true)) {
                    $mode = substr($arguments[$i], 2);
                } elseif ($arguments[$i] === '--format' && ($arguments[$i + 1] ?? null) === 'json') {
                    $json = true;
                    $i++;
                } else {
                    throw new \InvalidArgumentException('Usage: check-php-dependencies.php [--local-only|--advisories-only|--image-drift] [--format json]');
                }
            }
            $configuration = ToolingConfiguration::forRepository($root, $environment);
            $executor = new EngineExecutor(ExecutionContext::bounded($configuration->budgets->seconds('dependencies'), $environment, $root));
            try {
                $check = new Check($executor, $configuration, $environment);
                $report = match ($mode) {
                    'advisories-only' => $check->advisories($root),
                    'image-drift' => $check->imageDrift($root),
                    default => $check->run($root, $mode === 'local-only'),
                };
                fwrite(STDOUT, $report->render($json));
                return $report->exitCode();
            } finally {
                $executor->close();
            }
        } catch (\Throwable $error) {
            $report = new Report($mode === 'local-only', $mode === 'advisories-only', $mode === 'image-drift');
            $report->add('gap', 'repository', 'command', 'tooling', $error->getMessage());
            fwrite(STDOUT, $report->render($json));
            return 2;
        }
    }
}
