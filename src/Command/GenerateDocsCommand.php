<?php

declare(strict_types=1);

namespace App\Command;

use App\Command\DocGenerator\CodebaseScanner;
use App\Command\DocGenerator\HtmlRenderer;
use App\Command\DocGenerator\MarkdownConverter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:generate-docs',
    description: 'Generate the documentation site from source code and docs-book/.',
)]
final class GenerateDocsCommand extends Command
{
    private const DEFAULT_OUTPUT_DIR = 'docs/html';
    private const DEFAULT_PHPDOC_DIR = '.phpdoc/build';
    private const DEFAULT_SOURCE_DIR = 'src';
    private const DEFAULT_DOCS_BOOK_DIR = 'docs-book';

    public function __construct(
        private readonly CodebaseScanner $scanner,
        private readonly MarkdownConverter $markdownConverter,
        private readonly HtmlRenderer $renderer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('output-dir', 'o', InputOption::VALUE_REQUIRED, 'Output directory for generated docs', self::DEFAULT_OUTPUT_DIR)
            ->addOption('phpdoc-dir', null, InputOption::VALUE_REQUIRED, 'Path to phpDocumentor build output', self::DEFAULT_PHPDOC_DIR)
            ->addOption('source-dir', 's', InputOption::VALUE_REQUIRED, 'Path to source code', self::DEFAULT_SOURCE_DIR)
            ->addOption('docs-book-dir', null, InputOption::VALUE_REQUIRED, 'Path to docs-book markdown', self::DEFAULT_DOCS_BOOK_DIR);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $outputDir = $input->getOption('output-dir');
        $phpdocDir = $input->getOption('phpdoc-dir');
        $sourceDir = $input->getOption('source-dir');
        $docsBookDir = $input->getOption('docs-book-dir');

        if (!is_dir($sourceDir)) {
            $io->error(sprintf('Source directory "%s" does not exist.', $sourceDir));

            return Command::FAILURE;
        }

        if (!is_dir($phpdocDir)) {
            $io->warning(sprintf('phpDocumentor output directory "%s" not found. phpDocumentor links will not work.', $phpdocDir));
        }

        $this->cleanDirectory($outputDir);
        mkdir($outputDir . '/css', 0755, true);
        mkdir($outputDir . '/contexts', 0755, true);

        $io->section('Scanning codebase');
        $contexts = $this->scanner->scan($sourceDir);
        $io->text(sprintf('Found %d bounded contexts', count($contexts)));

        $totalRoutes = 0;
        $totalModels = 0;
        foreach ($contexts as $context) {
            $totalRoutes += count($context->routes);
            $totalModels += count($context->aggregateRoots) + count($context->valueObjects);
        }
        $io->text(sprintf('Found %d routes, %d domain models', $totalRoutes, $totalModels));

        $io->section('Converting markdown documentation');
        $convertedPages = [];
        if (is_dir($docsBookDir)) {
            $convertedPages = $this->markdownConverter->convert($docsBookDir, $outputDir, $phpdocDir);
            $io->text(sprintf('Converted %d markdown pages', count($convertedPages)));
        } else {
            $io->warning(sprintf('docs-book directory "%s" not found. Skipping markdown conversion.', $docsBookDir));
        }

        $io->section('Generating documentation pages');
        $this->renderer->renderAll($contexts, $outputDir, $phpdocDir);

        $this->renderer->writeCss($outputDir);

        $io->section('Writing index page');
        $this->renderer->writeIndex($contexts, $outputDir, $phpdocDir, $convertedPages);

        $io->success(sprintf(
            'Documentation site generated in %s (%d contexts, %d routes, %d models, %d markdown pages)',
            $outputDir,
            count($contexts),
            $totalRoutes,
            $totalModels,
            count($convertedPages),
        ));

        return Command::SUCCESS;
    }

    private function cleanDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }

        rmdir($path);
    }
}
