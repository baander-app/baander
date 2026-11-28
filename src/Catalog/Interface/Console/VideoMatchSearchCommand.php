<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Console;

use App\Catalog\Application\Service\VideoMatch\VideoMatchAdminService;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;
use App\Catalog\Interface\Resource\VideoMatchCandidatesResource;
use App\Shared\Interface\Console\TerminalText;

#[AsCommand(name: 'app:video:match:search', description: 'Search video match candidates or browse edit choices.')]
final class VideoMatchSearchCommand extends Command
{
    public function __construct(private readonly VideoMatchAdminService $matches)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('kind', InputArgument::REQUIRED, 'series or movie')
            ->addArgument('public-id', InputArgument::REQUIRED, 'Title public ID')
            ->addOption('query', null, InputOption::VALUE_REQUIRED, 'Search this title instead of the stored title')
            ->addOption('view', null, InputOption::VALUE_REQUIRED, 'search or choices', 'search')
            ->addOption('collection', null, InputOption::VALUE_REQUIRED, 'all, orderings, episodes or files', 'all')
            ->addOption('page', null, InputOption::VALUE_REQUIRED, 'Choices page, starting at 1', '1')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Choices per page, between 1 and 100', '50');
        AdminCommandSupport::addJsonOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $result = $this->matches->candidates(
                (string) $input->getArgument('kind'),
                (string) $input->getArgument('public-id'),
                AdminCommandSupport::stringOption($input, 'query'),
                AdminCommandSupport::stringOption($input, 'view') ?? 'search',
                AdminCommandSupport::stringOption($input, 'collection') ?? 'all',
                AdminCommandSupport::integerOption($input, 'page') ?? 1,
                AdminCommandSupport::integerOption($input, 'limit') ?? 50,
            );
            $data = VideoMatchCandidatesResource::from($result);
        } catch (Throwable $failure) {
            return AdminCommandSupport::fail($io, $failure);
        }
        if (AdminCommandSupport::wantsJson($input)) {
            return AdminCommandSupport::json($io, $data);
        }

        if (($data['view'] ?? null) === 'choices') {
            $io->definitionList(['Provider' => $data['provider'] ?? 'Unmatched'], ['Current ordering' => TerminalText::literal($data['currentOrdering'] ?? '-')], ['Ordering problem' => $data['orderingProblem'] ?? '-']);
            $rows = array_map(static fn (array $ordering): array => [$ordering['id'], $ordering['label'], $ordering['kind']], $data['orderings']);
            $io->table(['Ordering ID', 'Label', 'Kind'], TerminalText::literalRows($rows));
            foreach (['episodes', 'files'] as $collection) {
                if ($data[$collection] !== null) {
                    $io->section(ucfirst($collection) . ' (page ' . $data[$collection]['page'] . ', total ' . $data[$collection]['total'] . ')');
                    $rows = array_map(static fn (array $item): array => [$item['publicId'], $item['title'] ?? $item['path']], $data[$collection]['items']);
                    $io->table(['Public ID', 'Title or path'], TerminalText::literalRows($rows));
                }
            }

            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($data['providers'] as $provider) {
            foreach ($provider['candidates'] as $candidate) {
                $rows[] = [$provider['provider'], $candidate['id'], $candidate['title'], (string) ($candidate['year'] ?? '-')];
            }
            if ($provider['problem'] !== null) {
                $rows[] = [$provider['provider'], '-', $provider['problem'], '-'];
            }
        }
        $io->table(['Provider', 'Candidate ID', 'Title or problem', 'Year'], TerminalText::literalRows($rows));

        return Command::SUCCESS;
    }
}
