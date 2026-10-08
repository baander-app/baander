<?php

declare(strict_types=1);

namespace App\Radio\Interface\Console;

use App\Radio\Application\Port\RadioSourcePortInterface;
use App\Radio\Interface\Request\CreateRadioSourceRequest;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

/**
 * The CLI counterpart of POST /api/radio/sources.
 *
 * The options are the request's fields, checked with the request's constraints, so
 * rejected input gets the message and the per-field messages of the API's 422 response.
 */
#[AsCommand(
    name: 'app:radio:source:create',
    description: 'Create a radio source that station data is synced from.',
)]
final class RadioSourceCreateCommand extends Command
{
    public function __construct(
        private readonly RadioSourcePortInterface $sources,
        private readonly ValidatorInterface $validator,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Source name')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Source type, such as iprd')
            ->addOption('sync-url', null, InputOption::VALUE_REQUIRED, 'URL the station data is synced from')
            ->addOption('sync-config', null, InputOption::VALUE_REQUIRED, 'Source configuration as a JSON object; defaults to {}')
            ->addOption('sync-schedule', null, InputOption::VALUE_REQUIRED, 'Cron expression for the sync, such as "0 */6 * * *"');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $request = $this->request($input);
            $source = $this->sources->createSource(
                name: $request->name,
                type: $request->type,
                syncUrl: $request->syncUrl,
                syncConfig: $request->syncConfig,
                syncSchedule: $request->syncSchedule,
            );
        } catch (Throwable $failure) {
            return AdminCommandSupport::fail($io, $failure);
        }

        $io->success(sprintf('Created radio source "%s" (%s).', $source['name'], $source['id']));

        return Command::SUCCESS;
    }

    /** @throws InvalidInputException with the API's validation message and the messages per field */
    private function request(InputInterface $input): CreateRadioSourceRequest
    {
        $request = new CreateRadioSourceRequest(
            name: AdminCommandSupport::stringOption($input, 'name') ?? '',
            type: AdminCommandSupport::stringOption($input, 'type') ?? '',
            syncUrl: AdminCommandSupport::stringOption($input, 'sync-url') ?? '',
            syncConfig: AdminCommandSupport::jsonObjectOption($input, 'sync-config') ?? [],
            syncSchedule: AdminCommandSupport::stringOption($input, 'sync-schedule'),
        );

        $violations = $this->validator->validate($request);
        if ($violations->count() === 0) {
            return $request;
        }

        $details = [];
        foreach ($violations as $violation) {
            $details[$violation->getPropertyPath()][] = (string) $violation->getMessage();
        }

        throw new InvalidInputException($this->translator->trans('errors.validation.failed'), $details);
    }
}
