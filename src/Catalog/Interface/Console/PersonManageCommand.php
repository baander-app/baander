<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Console;

use App\Catalog\Application\Command\Person\ManagePersonCommand;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'app:person:manage', description: 'Correct Person profiles, identifiers, Artist links, or duplicate identities.')]
final class PersonManageCommand extends Command
{
    public function __construct(private readonly AdminCommandSupport $support)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::REQUIRED, 'create, edit, release-field, add-id, remove-id, link-artist, unlink-artist, conflicts, or merge')
            ->addArgument('person', InputArgument::OPTIONAL, 'Person public ID, or source Person for merge')
            ->addArgument('value', InputArgument::OPTIONAL, 'Profile field, external ID, Artist public ID, or survivor Person public ID')
            ->addOption('namespace', null, InputOption::VALUE_REQUIRED, 'Provider and entity kind for identifier changes')
            ->addOption('fields', null, InputOption::VALUE_REQUIRED, 'JSON object of profile fields to set or clear')
            ->addOption('prefer-survivor', null, InputOption::VALUE_NONE, 'Keep the survivor’s values when operator corrections conflict');
        AdminCommandSupport::addForceOption($this);
        AdminCommandSupport::addJsonOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $action = (string) $input->getArgument('action');
        if (in_array($action, ['merge', 'remove-id', 'unlink-artist'], true)) {
            $exit = AdminCommandSupport::confirm($input, $io, 'Apply this Person identity change?');
            if ($exit !== null) {
                return $exit;
            }
        }

        try {
            $person = $input->getArgument('person');
            $value = $input->getArgument('value');
            $data = $this->support->dispatch(new ManagePersonCommand(
                action: $action,
                person: is_string($person) ? $person : null,
                value: is_string($value) ? $value : null,
                namespace: AdminCommandSupport::stringOption($input, 'namespace'),
                profile: AdminCommandSupport::jsonObjectOption($input, 'fields') ?? [],
                preferTarget: (bool) $input->getOption('prefer-survivor'),
            ));
        } catch (Throwable $error) {
            return AdminCommandSupport::fail($io, $error);
        }

        return AdminCommandSupport::json($io, $data);
    }
}
