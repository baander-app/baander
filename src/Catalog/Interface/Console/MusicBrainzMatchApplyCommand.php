<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Console;

use App\Catalog\Application\Command\MusicBrainzMatch\ApplyMatchCommand;
use App\Catalog\Application\Command\MusicBrainzMatch\MatchApplyResult;
use App\Catalog\Application\Query\MusicBrainzMatch\CompareMatchQuery;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchComparison;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchItemType;
use App\Catalog\Interface\Request\ApplyMatchRequest;
use App\Catalog\Interface\Resource\MatchApplyResultResource;
use App\Catalog\Interface\Resource\MatchComparisonResource;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * The CLI counterpart of POST /api/admin/metadata/match/{type}/{publicId}/apply.
 *
 * With --selection the command applies the rows and artist actions the option names, as the API
 * body does. Without it, the command compares the item with the candidate, prints the comparison,
 * and applies what the comparison ticks by default, with the current values it just showed.
 * Either way it asks first, since a match can merge and remove artists.
 */
#[AsCommand(
    name: 'app:metadata:match:apply',
    description: 'Apply a MusicBrainz candidate to an album, song or artist: the rows ticked by default, or the ones --selection names.',
)]
final class MusicBrainzMatchApplyCommand extends Command
{
    private const string SELECTION = 'selection';

    public function __construct(
        private readonly AdminCommandSupport $support,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        MatchItemArguments::add($this);
        MatchItemArguments::addCandidate($this);
        $this->addOption(
            self::SELECTION,
            null,
            InputOption::VALUE_REQUIRED,
            'The rows to apply, as the API body\'s JSON object: "fields" and "credits" by key with the current value the comparison showed, "artistActions" and "keptArtists" by artist public ID',
        );
        AdminCommandSupport::addForceOption($this);
        AdminCommandSupport::addJsonOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $type = MatchItemArguments::type($input);
            $publicId = MatchItemArguments::publicId($input);
            $request = $this->request($input, $io, $type, $publicId);
            $selection = $request->selection();
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }

        $declined = AdminCommandSupport::confirm($input, $io, sprintf(
            'Apply MusicBrainz %s %s to %s %s%s?',
            $type->candidateNoun(),
            $request->candidate,
            $type->label() |> strtolower(...),
            $publicId,
            AdminCommandSupport::stringOption($input, self::SELECTION) === null ? ', as ticked by default' : '',
        ));
        if ($declined !== null) {
            return $declined;
        }

        try {
            $result = $this->support->dispatch(ApplyMatchCommand::manual($type, $publicId, $request->candidate, $selection));
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }
        assert($result instanceof MatchApplyResult);

        $data = MatchApplyResultResource::from($result);
        if (AdminCommandSupport::wantsJson($input)) {
            return AdminCommandSupport::json($io, $data);
        }

        $io->success(sprintf('MusicBrainz %s %s is applied to %s %s.', $type->candidateNoun(), $data['candidateId'], $type->label() |> strtolower(...), $publicId));
        $io->definitionList(
            ['Fields written' => MusicBrainzMatchOutput::cell($data['fields'])],
            ['Credits changed' => AdminCommandSupport::yesNo($data['creditsChanged'])],
            ['Songs changed' => (string) $data['songsChanged']],
            ['Artists merged' => MusicBrainzMatchOutput::cell($data['mergedArtists'])],
            ['Artists removed' => MusicBrainzMatchOutput::cell($data['removedArtists'])],
        );

        return Command::SUCCESS;
    }

    /**
     * The request --selection names, or the one that applies the comparison's default ticks, after
     * printing that comparison unless the output is JSON.
     */
    private function request(InputInterface $input, SymfonyStyle $io, MatchItemType $type, string $publicId): ApplyMatchRequest
    {
        $candidate = MatchItemArguments::candidate($input);
        $selection = AdminCommandSupport::jsonObjectOption($input, self::SELECTION);
        if ($selection !== null) {
            return ApplyMatchRequest::fromSelection($candidate, $selection);
        }

        $comparison = $this->support->dispatch(new CompareMatchQuery($type, $publicId, $candidate));
        assert($comparison instanceof MatchComparison);
        if (!AdminCommandSupport::wantsJson($input)) {
            MatchComparisonOutput::show($io, $comparison);
        }

        return MatchComparisonResource::from($comparison) |> ApplyMatchRequest::withDefaultTicks(...);
    }
}
