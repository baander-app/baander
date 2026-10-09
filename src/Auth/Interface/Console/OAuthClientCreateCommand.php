<?php

declare(strict_types=1);

namespace App\Auth\Interface\Console;

use App\Auth\Application\Command\OAuth\RegisterClientCommand;
use App\Auth\Application\DTO\RegisteredClientDTO;
use App\Auth\Interface\Resource\AdminOAuthClientCredentialsResource;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/** The CLI counterpart of POST /api/admin/oauth/clients. */
#[AsCommand(
    name: 'app:oauth:client:create',
    description: 'Register a device, public or confidential OAuth client.',
)]
final class OAuthClientCreateCommand extends Command
{
    public function __construct(
        private readonly OAuthClientMessageDispatcher $dispatcher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Name the user sees when approving the client')
            ->addOption('type', 't', InputOption::VALUE_REQUIRED, 'device, public or confidential')
            ->addOption('redirect-uri', 'r', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Redirect URI of a public or confidential client (repeatable)');
        AdminCommandSupport::addJsonOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $type = (string) $input->getOption('type');
        if (!in_array($type, ['device', 'public', 'confidential'], true)) {
            $io->getErrorStyle()->error('The --type option must be device, public or confidential.');

            return Command::INVALID;
        }

        /** @var list<string> $redirectUris */
        $redirectUris = array_values((array) $input->getOption('redirect-uri'));

        try {
            $registered = $this->dispatcher->dispatch(new RegisterClientCommand(
                name: (string) $input->getArgument('name'),
                type: $type,
                redirectUris: $redirectUris,
            ));
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }
        assert($registered instanceof RegisteredClientDTO);
        $client = AdminOAuthClientCredentialsResource::from($registered);

        if (AdminCommandSupport::wantsJson($input)) {
            return AdminCommandSupport::json($io, $client);
        }

        $io->success('The OAuth client is registered.');
        OAuthClientMessageDispatcher::show($io, $client);

        return Command::SUCCESS;
    }
}
