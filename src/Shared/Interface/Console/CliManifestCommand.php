<?php

declare(strict_types=1);

namespace App\Shared\Interface\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Application;

#[AsCommand(
    name: 'app:cli:manifest',
    description: 'Output a JSON manifest of all CLI commands and tooling metadata.',
)]
final class CliManifestCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption(
            'tooling-only',
            null,
            InputOption::VALUE_NONE,
            'Only output tooling metadata (skip console commands)',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $manifest = [];

        if (!$input->getOption('tooling-only')) {
            $manifest['console'] = $this->introspectConsole();
        }

        $manifest['phpstan'] = $this->introspectPhpstan();
        $manifest['deptrac'] = $this->introspectDeptrac();
        $manifest['composer'] = $this->introspectComposer();
        $manifest['phpunit'] = $this->introspectPhpunit();
        $manifest['paratest'] = $this->introspectParatest();

        $output->write(json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return Command::SUCCESS;
    }

    private function introspectConsole(): array
    {
        $application = $this->getApplication();
        \assert($application instanceof Application);

        $commands = [];
        foreach ($application->all() as $command) {
            try {
                $definition = $command->getDefinition();
            } catch (\Throwable) {
                // Some commands fail during instantiation (private property access, etc.)
                // Still record their name and description from the Application registry
                $commands[] = [
                    'name' => $command->getName(),
                    'description' => $command->getDescription(),
                    'aliases' => $command->getAliases(),
                    'hidden' => $command->isHidden(),
                    'arguments' => [],
                    'options' => [],
                ];
                continue;
            }

            $arguments = [];
            foreach ($definition->getArguments() as $arg) {
                $arguments[] = [
                    'name' => $arg->getName(),
                    'description' => $arg->getDescription(),
                    'default' => $arg->getDefault(),
                    'required' => $arg->isRequired(),
                ];
            }

            $options = [];
            foreach ($definition->getOptions() as $opt) {
                $options[] = [
                    'name' => $opt->getName(),
                    'shortcut' => $opt->getShortcut(),
                    'description' => $opt->getDescription(),
                    'default' => $opt->getDefault(),
                    'isValueRequired' => $opt->isValueRequired(),
                    'isArray' => $opt->isArray(),
                ];
            }

            $commands[] = [
                'name' => $command->getName(),
                'description' => $command->getDescription(),
                'aliases' => $command->getAliases(),
                'hidden' => $command->isHidden(),
                'arguments' => $arguments,
                'options' => $options,
            ];
        }

        return ['commands' => $commands];
    }

    private function introspectPhpstan(): array
    {
        $config = [
            'config' => 'phpstan.dist.neon',
            'level' => null,
            'memoryLimit' => '512M',
            'paths' => [],
        ];

        $configPath = getcwd() . '/phpstan.dist.neon';
        if (file_exists($configPath)) {
            $content = file_get_contents($configPath);

            if ($content !== false) {
                // Extract level
                if (preg_match('/^\s*level:\s*(\d+)/m', $content, $m)) {
                    $config['level'] = (int) $m[1];
                }

                // Extract paths
                if (preg_match('/^\s*paths:\s*\n((?:\s*-\s*.+\n?)+)/m', $content, $m)) {
                    preg_match_all('/-\s*(.+)/', $m[1], $paths);
                    $config['paths'] = array_map('trim', $paths[1]);
                }

                // Extract memory limit from parameters
                if (preg_match('/memoryLimit:\s*[\'"]?([^\'"\n]+)/m', $content, $m)) {
                    $config['memoryLimit'] = trim($m[1]);
                }
            }
        }

        return $config;
    }

    private function introspectDeptrac(): array
    {
        $config = [
            'config' => 'deptrac.yaml',
            'layers' => [],
        ];

        $configPath = getcwd() . '/deptrac.yaml';
        if (file_exists($configPath)) {
            $content = file_get_contents($configPath);

            if ($content !== false) {
                // Match layer names: - name: 'Layer Name' or - name: LayerName
                if (preg_match_all('/-\s*name:\s*[\'\"]?(.+?)[\'\"]?\s*$/m', $content, $m)) {
                    $config['layers'] = array_map('trim', $m[1]);
                }
            }
        }

        return $config;
    }

    private function introspectComposer(): array
    {
        $config = [
            'scripts' => [],
            'autoload' => [],
        ];

        $composerPath = getcwd() . '/composer.json';
        if (file_exists($composerPath)) {
            $content = file_get_contents($composerPath);

            if ($content !== false) {
                $data = json_decode($content, true);
                if (is_array($data)) {
                    $config['scripts'] = $data['scripts'] ?? [];
                    $config['autoload'] = $data['autoload'] ?? [];
                }
            }
        }

        return $config;
    }

    private function introspectPhpunit(): array
    {
        return [
            'config' => 'phpunit.xml',
            'coverage' => true,
        ];
    }

    private function introspectParatest(): array
    {
        return [
            'processes' => 'auto',
            'config' => 'phpunit.xml',
        ];
    }
}
