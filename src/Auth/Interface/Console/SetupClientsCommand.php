<?php

declare(strict_types=1);

namespace App\Auth\Interface\Console;

use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Repository\OAuth\ClientRepositoryInterface;
use App\Shared\Domain\Model\PublicId;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:auth:setup-clients',
    description: 'Create OAuth2 password clients for the SPA and Electron app.',
)]
final class SetupClientsCommand extends Command
{
    /**
     * Fixed, deterministic public IDs for the first-party dev clients.
     *
     * Using fixed IDs means the values in .env (AUTH_SPA_CLIENT_ID /
     * AUTH_ELECTRON_CLIENT_ID) always resolve to a client row, even after a
     * full database reset (app:dev:setup --fresh). They are 21-char NanoID-
     * compatible strings (letters, digits, underscore). The public ID is an
     * identifier, not a secret — client confidentiality comes from secrets.
     */
    public const string SPA_PUBLIC_ID = 'baander_dev_spa_00001';
    public const string ELECTRON_PUBLIC_ID = 'baander_dev_elc_00001';

    public function __construct(
        private readonly ClientRepositoryInterface $clientRepository,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $spa = $this->seedClient(
            'Bånder SPA',
            PublicId::fromString(self::SPA_PUBLIC_ID),
            $io,
        );
        $electron = $this->seedClient(
            'Bånder Electron',
            PublicId::fromString(self::ELECTRON_PUBLIC_ID),
            $io,
        );

        $io->success('OAuth clients are ready.');
        $io->table(
            ['App', 'Public ID', 'Name'],
            [
                ['SPA', $spa->getPublicId()->toString(), $spa->getName()],
                ['Electron', $electron->getPublicId()->toString(), $electron->getName()],
            ],
        );

        $io->section('These IDs are already set in .env for the dev environment:');
        $io->text('AUTH_SPA_CLIENT_ID=' . $spa->getPublicId()->toString());
        $io->text('AUTH_ELECTRON_CLIENT_ID=' . $electron->getPublicId()->toString());

        return Command::SUCCESS;
    }

    /**
     * Find-or-create a client by its fixed public ID, so the command is
     * idempotent and safe to run repeatedly (including after a DB reset).
     */
    private function seedClient(string $name, PublicId $publicId, SymfonyStyle $io): Client
    {
        $existing = $this->clientRepository->findClientByPublicId($publicId);

        if ($existing !== null) {
            $io->text(sprintf('<comment>•</comment> Client exists: %s (%s)', $name, $publicId->toString()));

            return $existing;
        }

        $client = Client::create(
            name: $name,
            redirectUris: ['http://localhost'],
            firstParty: true,
            passwordClient: true,
            publicId: $publicId,
        );
        $this->clientRepository->saveClient($client);

        $io->text(sprintf('<info>✓</info> Created client: %s (%s)', $name, $publicId->toString()));

        return $client;
    }
}
