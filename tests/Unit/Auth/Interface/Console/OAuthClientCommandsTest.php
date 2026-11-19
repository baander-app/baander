<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Interface\Console;

use App\Auth\Application\Command\OAuth\RegisterClientCommand;
use App\Auth\Application\Command\OAuth\ReplaceClientKeysCommand;
use App\Auth\Application\Command\OAuth\RevokeRegisteredClientCommand;
use App\Auth\Application\Command\OAuth\RotateClientSecretCommand;
use App\Auth\Application\DTO\RegisteredClientDTO;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\OAuth\ValueObject\ClientKeySource;
use App\Auth\Domain\Model\OAuth\ValueObject\ClientSecret;
use App\Auth\Interface\Console\OAuthClientCreateCommand;
use App\Auth\Interface\Console\OAuthClientMessageDispatcher;
use App\Auth\Interface\Console\OAuthClientReplaceKeysCommand;
use App\Auth\Interface\Console\OAuthClientRevokeCommand;
use App\Auth\Interface\Console\OAuthClientRotateSecretCommand;
use App\Auth\Interface\Resource\AdminOAuthClientCredentialsResource;
use App\Auth\Interface\Resource\AdminOAuthClientResource;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Interface\Console\AdminCommandSupport;
use App\Tests\Fixtures\Auth\SignedClientAssertion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/** The app:oauth:client:* write commands print the API's data with --json and exit as the API answers. */
final class OAuthClientCommandsTest extends TestCase
{
    private ?\Throwable $failure = null;
    private Client $client;
    private Client $keyClient;
    private RegisteredClientDTO $registered;
    /** @var list<object> */
    private array $dispatched = [];
    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        $this->client = Client::registerConfidential('Console server', ['https://console.baander.app/callback'], ClientSecret::fromString('secret'));
        $this->keyClient = Client::registerConfidentialWithKeys('Signing server', ['https://console.baander.app/callback'], ClientKeySource::fromJwksUri('https://console.baander.app/jwks.json'));
        $this->registered = new RegisteredClientDTO($this->client, 'plain-secret');
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), $this->files);
    }

    public function testCreateAndRotatePrintTheClientWithItsSecretAsTheApiReturnsIt(): void
    {
        $expected = AdminOAuthClientCredentialsResource::from($this->registered);

        $create = new CommandTester(new OAuthClientCreateCommand($this->dispatcher()));
        self::assertSame(Command::SUCCESS, $create->execute([
            'name' => 'Console server',
            '--type' => 'confidential',
            '--redirect-uri' => ['https://console.baander.app/callback'],
            '--json' => true,
        ]));
        self::assertSame($expected, $this->json($create));

        $rotate = new CommandTester(new OAuthClientRotateSecretCommand($this->dispatcher()));
        self::assertSame(Command::SUCCESS, $rotate->execute(['client-id' => $this->client->getPublicId()->toString(), '--json' => true]));
        self::assertSame($expected, $this->json($rotate));
    }

    public function testCreateReadsAKeyClientsJwksFromAFile(): void
    {
        $jwks = (new SignedClientAssertion())->jwks();
        $create = new CommandTester(new OAuthClientCreateCommand($this->dispatcher()));

        self::assertSame(Command::SUCCESS, $create->execute([
            'name' => 'Signing server',
            '--type' => 'confidential',
            '--redirect-uri' => ['https://console.baander.app/callback'],
            '--auth-method' => 'private_key_jwt',
            '--jwks-file' => $this->file(json_encode($jwks, JSON_THROW_ON_ERROR)),
        ]), $create->getDisplay());

        self::assertEquals(
            [new RegisterClientCommand('Signing server', 'confidential', ['https://console.baander.app/callback'], 'private_key_jwt', $jwks)],
            $this->dispatched,
        );
    }

    public function testCreatePassesAJwksUri(): void
    {
        $create = new CommandTester(new OAuthClientCreateCommand($this->dispatcher()));

        self::assertSame(Command::SUCCESS, $create->execute([
            'name' => 'Signing server',
            '--type' => 'confidential',
            '--redirect-uri' => ['https://console.baander.app/callback'],
            '--auth-method' => 'private_key_jwt',
            '--jwks-uri' => 'https://console.baander.app/jwks.json',
        ]), $create->getDisplay());

        self::assertEquals(
            [new RegisterClientCommand('Signing server', 'confidential', ['https://console.baander.app/callback'], 'private_key_jwt', jwksUri: 'https://console.baander.app/jwks.json')],
            $this->dispatched,
        );
    }

    public function testReplaceKeysPrintsTheClientAsTheApiReturnsIt(): void
    {
        $replace = new CommandTester(new OAuthClientReplaceKeysCommand($this->dispatcher()));

        self::assertSame(Command::SUCCESS, $replace->execute([
            'client-id' => $this->keyClient->getPublicId()->toString(),
            '--jwks-uri' => 'https://console.baander.app/jwks.json',
            '--json' => true,
        ]));

        self::assertSame(AdminOAuthClientResource::from($this->keyClient), $this->json($replace));
        self::assertEquals([new ReplaceClientKeysCommand($this->keyClient->getPublicId(), jwksUri: 'https://console.baander.app/jwks.json')], $this->dispatched);
    }

    /** @return iterable<string, array{string}> */
    public static function unusableJwksFiles(): iterable
    {
        yield 'not JSON' => ['{"keys": ['];
        yield 'a JSON list' => ['[1, 2]'];
    }

    #[DataProvider('unusableJwksFiles')]
    public function testAJwksFileThatIsNotAJsonObjectIsInvalid(string $contents): void
    {
        $replace = new CommandTester(new OAuthClientReplaceKeysCommand($this->dispatcher()));

        self::assertSame(Command::INVALID, $replace->execute([
            'client-id' => $this->keyClient->getPublicId()->toString(),
            '--jwks-file' => $this->file($contents),
        ]));
        self::assertStringContainsString('must hold a JSON object', self::display($replace));
        self::assertSame([], $this->dispatched);
    }

    public function testAMissingJwksFileIsInvalid(): void
    {
        $replace = new CommandTester(new OAuthClientReplaceKeysCommand($this->dispatcher()));

        self::assertSame(Command::INVALID, $replace->execute([
            'client-id' => $this->keyClient->getPublicId()->toString(),
            '--jwks-file' => sys_get_temp_dir() . '/baander-no-such-jwks.json',
        ]));
        self::assertStringContainsString('Cannot read the JWK Set file', self::display($replace));
        self::assertSame([], $this->dispatched);
    }

    public function testRevokePrintsTheClientAsTheApiReturnsIt(): void
    {
        $revoke = new CommandTester(new OAuthClientRevokeCommand($this->dispatcher()));

        self::assertSame(Command::SUCCESS, $revoke->execute(['client-id' => $this->client->getPublicId()->toString(), '--json' => true]));
        self::assertSame(AdminOAuthClientResource::from($this->client), $this->json($revoke));
    }

    public function testARegistrationTheApiRejectsWith422IsInvalid(): void
    {
        $this->failure = new InvalidInputException('A device client has no redirect URIs.', ['reason' => 'invalid_registration']);
        $create = new CommandTester(new OAuthClientCreateCommand($this->dispatcher()));

        self::assertSame(Command::INVALID, $create->execute(['name' => 'TV', '--type' => 'device', '--redirect-uri' => ['https://tv.baander.app/callback']]));
        self::assertStringContainsString('A device client has no redirect URIs.', $create->getDisplay());
    }

    public function testAChangeTheApiRejectsWith409Fails(): void
    {
        $this->failure = new ConflictException('The first-party and personal access clients cannot be changed here.', ['reason' => 'protected_client']);
        $revoke = new CommandTester(new OAuthClientRevokeCommand($this->dispatcher()));

        self::assertSame(Command::FAILURE, $revoke->execute(['client-id' => $this->client->getPublicId()->toString()]));
        self::assertStringContainsString('cannot be changed here', $revoke->getDisplay());
    }

    private function dispatcher(): OAuthClientMessageDispatcher
    {
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $message): Envelope {
            $this->dispatched[] = $message;
            if ($this->failure !== null) {
                throw new HandlerFailedException(new Envelope($message), [$this->failure]);
            }
            $result = match ($message::class) {
                RegisterClientCommand::class, RotateClientSecretCommand::class => $this->registered,
                RevokeRegisteredClientCommand::class => $this->client,
                ReplaceClientKeysCommand::class => $this->keyClient,
                default => self::fail('Unexpected message ' . $message::class),
            };

            return new Envelope($message, [new HandledStamp($result, 'handler')]);
        });

        return new OAuthClientMessageDispatcher(new AdminCommandSupport($bus));
    }

    private function file(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'baander-jwks-');
        self::assertIsString($path);
        file_put_contents($path, $contents);
        $this->files[] = $path;

        return $path;
    }

    /** The output with console line wrapping undone. */
    private static function display(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    /** @return array<array-key, mixed> */
    private function json(CommandTester $tester): array
    {
        $decoded = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
