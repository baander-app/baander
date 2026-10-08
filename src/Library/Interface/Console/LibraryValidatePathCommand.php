<?php

declare(strict_types=1);

namespace App\Library\Interface\Console;

use App\Library\Application\PathValidator;
use App\Library\Interface\Resource\PathValidationResource;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/** The CLI counterpart of POST /api/libraries/validate-path. */
#[AsCommand(
    name: 'app:library:validate-path',
    description: 'Check that a directory can serve as a media library.',
)]
final class LibraryValidatePathCommand extends Command
{
    public function __construct(
        private readonly PathValidator $pathValidator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('path', InputArgument::REQUIRED, 'Absolute path inside the container');
        AdminCommandSupport::addJsonOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $result = PathValidationResource::from($this->pathValidator->validateInput((string) $input->getArgument('path')));
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }

        if (AdminCommandSupport::wantsJson($input)) {
            AdminCommandSupport::json($io, $result);
        } else {
            $io->horizontalTable(
                ['Valid', 'Resolved path', 'Exists', 'Readable', 'Error'],
                [[
                    $result['valid'] ? 'yes' : 'no',
                    $result['resolvedPath'] ?? '-',
                    $result['exists'] ? 'yes' : 'no',
                    $result['readable'] ? 'yes' : 'no',
                    $result['error'] ?? '-',
                ]],
            );
        }

        return $result['valid'] ? Command::SUCCESS : Command::FAILURE;
    }
}
