<?php

declare(strict_types=1);

namespace App\Auth\Interface\Console;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Interface\Console\AdminCommandSupport;
use InvalidArgumentException;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Plumbing of the app:oauth:client:* commands: dispatch through the shared admin
 * command support, client IDs and client output.
 */
final readonly class OAuthClientMessageDispatcher
{
    public function __construct(
        private AdminCommandSupport $support,
    ) {
    }

    /**
     * Dispatches through the bus the admin API uses and unwraps the handler's own exception.
     *
     * @throws Throwable
     */
    public function dispatch(object $message): mixed
    {
        return $this->support->dispatch($message);
    }

    public static function publicId(string $value): PublicId
    {
        try {
            return PublicId::fromString($value);
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException(sprintf('"%s" is not a valid client ID.', $value));
        }
    }

    /**
     * Prints a client as the admin API shows it, and its secret when it was just generated.
     *
     * @param array<string, mixed> $client AdminOAuthClientResource or AdminOAuthClientCredentialsResource fields
     */
    public static function show(SymfonyStyle $io, array $client): void
    {
        $redirectUris = is_array($client['redirectUris'] ?? null) ? $client['redirectUris'] : [];
        $rows = [
            ['Client ID', $client['clientId'] ?? ''],
            ['Name', $client['name'] ?? ''],
            ['Type', $client['type'] ?? ''],
            ['Redirect URIs', $redirectUris === [] ? '-' : implode("\n", $redirectUris)],
            ['Revoked', AdminCommandSupport::yesNo(($client['revoked'] ?? false) === true)],
        ];
        $secret = $client['clientSecret'] ?? null;
        if (is_string($secret)) {
            $rows[] = ['Client secret', $secret];
        }
        $io->horizontalTable(array_column($rows, 0), [array_column($rows, 1)]);

        if (is_string($secret)) {
            $io->warning('Store the client secret now. Baander keeps only its digest and cannot show it again.');
        }
    }
}
