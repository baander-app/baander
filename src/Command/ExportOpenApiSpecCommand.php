<?php

declare(strict_types=1);

namespace App\Command;

use Nelmio\ApiDocBundle\Render\RenderOpenApi;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

#[AsCommand(
    name: 'app:export-openapi-spec',
    description: 'Export the OpenAPI specification to a JSON file.',
)]
final class ExportOpenApiSpecCommand extends Command
{
    public function __construct(
        private readonly RenderOpenApi $renderOpenApi,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output file path', 'openapi.json')
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Output format (json or yaml)', 'json')
            ->addOption('check', null, InputOption::VALUE_NONE, 'Fail if the existing specification differs; do not write files');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $outputPath = $input->getOption('output');
        $format = $input->getOption('format');

        if ($format !== 'json' && $format !== 'yaml') {
            $io->error('Format must be "json" or "yaml".');

            return Command::FAILURE;
        }

        try {
            $content = $this->renderOpenApi->render($format, 'default');
        } catch (\Throwable $e) {
            $io->error('Failed to generate OpenAPI specification: ' . $e->getMessage());

            return Command::FAILURE;
        }

        if ($input->getOption('check')) {
            if (!is_file($outputPath) || !is_readable($outputPath)) {
                $io->error('The OpenAPI specification is missing or unreadable.');

                return Command::FAILURE;
            }
            if (file_get_contents($outputPath) !== $content) {
                $io->error('The OpenAPI specification is out of date. Regenerate it and the API clients.');

                return Command::FAILURE;
            }
            $io->success('The OpenAPI specification matches the current application.');

            return Command::SUCCESS;
        }

        try {
            (new Filesystem())->dumpFile($outputPath, $content);
        } catch (\Throwable $e) {
            $io->error('Failed to write OpenAPI specification: ' . $e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('OpenAPI spec exported to %s (%s)', $outputPath, $format));

        return Command::SUCCESS;
    }
}
