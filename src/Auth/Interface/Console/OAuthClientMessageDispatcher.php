<?php

declare(strict_types=1);

namespace App\Auth\Interface\Console;

use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Interface\Console\AdminCommandSupport;
use InvalidArgumentException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Plumbing of the app:oauth:client:* commands: dispatch through the shared admin
 * command support, client IDs, key source options and client output.
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

    /** Adds --jwks-file and --jwks-uri, the two key sources of a private_key_jwt client. */
    public static function addKeySourceOptions(Command $command): void
    {
        $command
            ->addOption('jwks-file', null, InputOption::VALUE_REQUIRED, 'File holding the client\'s public keys as a JWK Set (JSON)')
            ->addOption('jwks-uri', null, InputOption::VALUE_REQUIRED, 'https URI where the client publishes its JWK Set');
    }

    /**
     * The JWK Set read from --jwks-file and the --jwks-uri value, each null when absent.
     * Whether exactly one is given is the use case's rule, as in the admin API.
     *
     * @return array{?array<string, mixed>, ?string}
     *
     * @throws InvalidInputException when the file cannot be read or holds no JSON object
     */
    public static function keySource(InputInterface $input): array
    {
        $file = AdminCommandSupport::stringOption($input, 'jwks-file');

        return [
            $file === null ? null : self::jwksFromFile($file),
            AdminCommandSupport::stringOption($input, 'jwks-uri'),
        ];
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
            ['Authentication', $client['authMethod'] ?? ''],
            ['Redirect URIs', $redirectUris === [] ? '-' : implode("\n", $redirectUris)],
            ['Revoked', AdminCommandSupport::yesNo(($client['revoked'] ?? false) === true)],
        ];
        $jwksUri = $client['jwksUri'] ?? null;
        if (is_string($jwksUri)) {
            $rows[] = ['JWKS URI', $jwksUri];
        }
        $secret = $client['clientSecret'] ?? null;
        if (is_string($secret)) {
            $rows[] = ['Client secret', $secret];
        }
        $io->horizontalTable(array_column($rows, 0), [array_column($rows, 1)]);

        if (is_string($secret)) {
            $io->warning('Store the client secret now. Baander keeps only its digest and cannot show it again.');
        }
    }

    /** @return array<string, mixed> */
    private static function jwksFromFile(string $path): array
    {
        $json = is_file($path) && is_readable($path) ? file_get_contents($path) : false;
        if ($json === false) {
            throw new InvalidInputException(sprintf('Cannot read the JWK Set file "%s".', $path));
        }

        try {
            $decoded = json_decode($json, false, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $decoded = null;
        }
        if (!$decoded instanceof \stdClass) {
            throw new InvalidInputException(sprintf('The JWK Set file "%s" must hold a JSON object.', $path));
        }

        /** @var array<string, mixed> */
        return json_decode($json, true, 32, JSON_THROW_ON_ERROR);
    }
}
