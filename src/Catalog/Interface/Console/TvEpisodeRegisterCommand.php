<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Console;

use App\Catalog\Application\Command\Tv\RegisterTvEpisodeCommand;
use App\Catalog\Application\DTO\TvTitleRecord;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Port\JobMonitorAdministrationInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'app:tv:episode:register', description: 'Register a TV episode and import its Person credits from TMDB.')]
final class TvEpisodeRegisterCommand extends Command
{
    public function __construct(private readonly JobMonitorAdministrationInterface $jobs)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('series', InputArgument::REQUIRED, 'Public ID of a registered TV series')
            ->addArgument('season', InputArgument::REQUIRED, 'Season number, with zero for specials')
            ->addArgument('episode', InputArgument::REQUIRED, 'Episode number')
            ->addOption('refresh-credits', null, InputOption::VALUE_NONE, 'Replace complete provider credit partitions');
        AdminCommandSupport::addJsonOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $run = $this->jobs->runInline(new RegisterTvEpisodeCommand(
                seriesId: new PublicId((string) $input->getArgument('series')),
                seasonNumber: self::integerArgument($input->getArgument('season'), 'Season number'),
                episodeNumber: self::integerArgument($input->getArgument('episode'), 'Episode number'),
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
