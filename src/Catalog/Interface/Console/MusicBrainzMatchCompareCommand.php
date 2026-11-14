<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Console;

use App\Catalog\Application\Query\MusicBrainzMatch\CompareMatchQuery;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchComparison;
use App\Catalog\Interface\Resource\MatchComparisonResource;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * The CLI counterpart of GET /api/admin/metadata/match/{type}/{publicId}/comparison.
 *
 * Prints what a match with the candidate would set, one row per field and per credits row with
 * the key an apply names it by, an album's track pairs with their song IDs, and the artists the
 * match would merge or remove with their public IDs. Changes nothing.
 */
#[AsCommand(
    name: 'app:metadata:match:compare',
    description: 'Compare an album, song or artist with a MusicBrainz candidate: what a match would set, row by row.',
)]
final class MusicBrainzMatchCompareCommand extends Command
{
    public function __construct(
        private readonly AdminCommandSupport $support,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        MatchItemArguments::add($this);
        MatchItemArguments::addCandidate($this);
        AdminCommandSupport::addJsonOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $comparison = $this->support->dispatch(new CompareMatchQuery(
                MatchItemArguments::type($input),
                MatchItemArguments::publicId($input),
                MatchItemArguments::candidate($input),
            ));
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }
        assert($comparison instanceof MatchComparison);

        if (AdminCommandSupport::wantsJson($input)) {
            return AdminCommandSupport::json($io, MatchComparisonResource::from($comparison));
        }

        MatchComparisonOutput::show($io, $comparison);

        return Command::SUCCESS;
    }
}
