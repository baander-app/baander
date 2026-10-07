<?php

declare(strict_types=1);

namespace App\Auth\Interface\Console;

use App\Shared\Domain\Model\PublicId;
use InvalidArgumentException;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Throwable;

/**
 * Shared plumbing of the app:oauth:client:* commands: synchronous dispatch and client output.
 */
final readonly class OAuthClientMessageDispatcher
{
    public function __construct(
        private MessageBusInterface $bus,
    ) {
    }

    /**
     * Dispatches through the bus the admin API uses and unwraps the handler's own exception.
     *
     * @throws Throwable
     */
    public function dispatch(object $message): mixed
    {
        try {
            return $this->bus->dispatch($message)->last(HandledStamp::class)?->getResult();
        } catch (HandlerFailedException $exception) {
            $wrapped = $exception->getWrappedExceptions();

            throw count($wrapped) === 1 ? reset($wrapped) : $exception;
        }
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
            ['Revoked', ($client['revoked'] ?? false) === true ? 'yes' : 'no'],
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
