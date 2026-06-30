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
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Output format (json or yaml)', 'json');
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

        file_put_contents($outputPath, $content);

        $io->success(sprintf('OpenAPI spec exported to %s (%s)', $outputPath, $format));

        return Command::SUCCESS;
    }
}
