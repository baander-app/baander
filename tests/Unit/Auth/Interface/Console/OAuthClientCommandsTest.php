<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Interface\Console;

use App\Auth\Application\Command\OAuth\RegisterClientCommand;
use App\Auth\Application\Command\OAuth\RevokeRegisteredClientCommand;
use App\Auth\Application\Command\OAuth\RotateClientSecretCommand;
use App\Auth\Application\DTO\RegisteredClientDTO;
use App\Auth\Application\Exception\ClientManagementException;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\OAuth\ValueObject\ClientSecret;
use App\Auth\Interface\Console\OAuthClientCreateCommand;
use App\Auth\Interface\Console\OAuthClientMessageDispatcher;
use App\Auth\Interface\Console\OAuthClientRevokeCommand;
use App\Auth\Interface\Console\OAuthClientRotateSecretCommand;
use App\Auth\Interface\Resource\AdminOAuthClientCredentialsResource;
use App\Auth\Interface\Resource\AdminOAuthClientResource;
use App\Shared\Interface\Console\AdminCommandSupport;
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
    private RegisteredClientDTO $registered;

    protected function setUp(): void
    {
        $this->client = Client::registerConfidential('Console server', ['https://console.baander.app/callback'], ClientSecret::fromString('secret'));
        $this->registered = new RegisteredClientDTO($this->client, 'plain-secret');
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

    public function testRevokePrintsTheClientAsTheApiReturnsIt(): void
    {
        $revoke = new CommandTester(new OAuthClientRevokeCommand($this->dispatcher()));

        self::assertSame(Command::SUCCESS, $revoke->execute(['client-id' => $this->client->getPublicId()->toString(), '--json' => true]));
        self::assertSame(AdminOAuthClientResource::from($this->client), $this->json($revoke));
    }

    public function testARegistrationTheApiRejectsWith422IsInvalid(): void
    {
        $this->failure = ClientManagementException::invalidRegistration('A device client has no redirect URIs.');
        $create = new CommandTester(new OAuthClientCreateCommand($this->dispatcher()));

        self::assertSame(Command::INVALID, $create->execute(['name' => 'TV', '--type' => 'device', '--redirect-uri' => ['https://tv.baander.app/callback']]));
        self::assertStringContainsString('A device client has no redirect URIs.', $create->getDisplay());
    }

    public function testAChangeTheApiRejectsWith409Fails(): void
    {
        $this->failure = ClientManagementException::protectedClient();
        $revoke = new CommandTester(new OAuthClientRevokeCommand($this->dispatcher()));

        self::assertSame(Command::FAILURE, $revoke->execute(['client-id' => $this->client->getPublicId()->toString()]));
        self::assertStringContainsString('cannot be changed here', $revoke->getDisplay());
    }

    private function dispatcher(): OAuthClientMessageDispatcher
    {
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $message): Envelope {
            if ($this->failure !== null) {
                throw new HandlerFailedException(new Envelope($message), [$this->failure]);
            }
            $result = match ($message::class) {
                RegisterClientCommand::class, RotateClientSecretCommand::class => $this->registered,
                RevokeRegisteredClientCommand::class => $this->client,
                default => self::fail('Unexpected message ' . $message::class),
            };

            return new Envelope($message, [new HandledStamp($result, 'handler')]);
        });

        return new OAuthClientMessageDispatcher(new AdminCommandSupport($bus));
    }

    /** @return array<array-key, mixed> */
    private function json(CommandTester $tester): array
    {
        $decoded = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
