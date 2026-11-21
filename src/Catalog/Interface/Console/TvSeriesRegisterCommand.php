<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Console;

use App\Catalog\Application\Command\Tv\RegisterTvSeriesCommand;
use App\Catalog\Application\DTO\TvTitleRecord;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Port\JobMonitorAdministrationInterface;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'app:tv:series:register', description: 'Register a TV series and import its Person credits from TMDB.')]
final class TvSeriesRegisterCommand extends Command
{
    public function __construct(private readonly JobMonitorAdministrationInterface $jobs)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('library', InputArgument::REQUIRED, 'Internal UUID of a TV show library')
            ->addArgument('tmdb-id', InputArgument::REQUIRED, 'Confirmed TMDB series ID')
            ->addOption('refresh-credits', null, InputOption::VALUE_NONE, 'Replace complete provider credit partitions');
        AdminCommandSupport::addJsonOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $run = $this->jobs->runInline(new RegisterTvSeriesCommand(
                libraryId: AdminCommandSupport::uuid($input->getArgument('library'), 'Library ID'),
                tmdbId: self::integerArgument($input->getArgument('tmdb-id'), 'TMDB ID'),
                forceUpdate: $input->getOption('refresh-credits') === true,
            ));
            $title = $run->result;
            if (!$title instanceof TvTitleRecord) {
                throw new \LogicException('TV registration did not return its title.');
            }
        } catch (Throwable $error) {
            return AdminCommandSupport::fail($io, $error);
        }
        if (AdminCommandSupport::wantsJson($input)) {
            return AdminCommandSupport::json($io, [
                'publicId' => $title->publicId->toString(),
                'name' => $title->name,
                'jobId' => $run->jobId,
            ]);
        }
        $io->success(sprintf('Registered "%s" (%s).', $title->name, $title->publicId->toString()));

        return Command::SUCCESS;
    }

    private static function integerArgument(mixed $value, string $name): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if ($integer === false) {
            throw new InvalidInputException($name . ' must be an integer.');
        }

        return $integer;
    }
}
