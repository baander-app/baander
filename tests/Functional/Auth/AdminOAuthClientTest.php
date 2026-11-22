<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use App\Auth\Application\Command\OAuth\IssueTokenCommand;
use App\Auth\Application\DTO\TokenResponseDTO;
use App\Auth\Application\Port\ClientAssertionVerifierInterface;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\OAuth\SignInGrant;
use App\Auth\Domain\Repository\OAuth\ClientRepositoryInterface;
use App\Shared\Domain\Model\PublicId;
use App\Tests\Fixtures\Auth\SignedClientAssertion;
use App\Tests\Functional\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/** Administrator OAuth client management through the admin API and the app:oauth:client:* commands. */
final class AdminOAuthClientTest extends TestCase
{
    private const string PATH = '/api/admin/oauth/clients';

    /** @var list<string> */
    private array $jwksFiles = [];

    protected function tearDown(): void
    {
        array_map(unlink(...), $this->jwksFiles);
        parent::tearDown();
    }

    public function testOnlyAdministratorsListAndOnlySuperAdministratorsChangeClients(): void
    {
        // First, before an authenticated request leaves a token in the reused kernel.
        self::assertSame(401, $this->anonymousRequest('GET', self::PATH)->getStatusCode());
        self::assertSame(401, $this->anonymousRequest('POST', self::PATH, ['name' => 'Anonymous TV', 'type' => 'device'])->getStatusCode());

        $user = $this->createTestUser();
        $admin = $this->createAdminUser();
        $superAdmin = $this->createSuperAdminUser();
        $device = $this->registerClient($superAdmin, ['name' => 'Role matrix TV', 'type' => 'device']);

        self::assertSame(403, $this->authenticatedRequest('GET', self::PATH, $user)->getStatusCode());
        $listed = $this->assertJsonResponse($this->authenticatedRequest('GET', self::PATH, $admin), 200, 'data')['data'];
        self::assertContains($device['clientId'], array_column($listed, 'clientId'));

        $routes = [
            ['POST', self::PATH, ['name' => 'Denied TV', 'type' => 'device']],
            ['POST', self::PATH . '/' . $device['clientId'] . '/rotate-secret', []],
            ['POST', self::PATH . '/' . $device['clientId'] . '/revoke', []],
            ['POST', self::PATH . '/' . $device['clientId'] . '/keys', ['jwksUri' => 'https://keys.baander.app/jwks.json']],
        ];
        foreach ([$user, $admin] as $caller) {
            foreach ($routes as [$method, $path, $body]) {
                self::assertSame(403, $this->authenticatedRequest($method, $path, $caller, $body)->getStatusCode(), $path);
            }
        }
        self::assertFalse($this->listed($admin, $device['clientId'])['revoked']);
    }

    public function testTheSecretIsShownOnCreationAndRotationOnly(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $created = $this->registerClient($superAdmin, [
            'name' => 'Server app',
            'type' => 'confidential',
            'redirectUris' => ['https://server.baander.app/callback'],
        ]);

        self::assertSame('confidential', $created['type']);
        self::assertSame(['https://server.baander.app/callback'], $created['redirectUris']);
        self::assertIsString($created['clientSecret']);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $created['clientSecret']);
        $stored = $this->entityManager->getConnection()->fetchOne('SELECT secret_hash FROM oauth_clients WHERE public_id = ?', [$created['clientId']]);
        self::assertSame(hash('sha256', $created['clientSecret']), $stored, 'Only the digest is stored.');
        self::assertArrayNotHasKey('clientSecret', $this->listed($superAdmin, $created['clientId']));

        $response = $this->authenticatedRequest('POST', self::PATH . '/' . $created['clientId'] . '/rotate-secret', $superAdmin);
        $rotated = $this->assertJsonResponse($response, 200, 'data')['data'];
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertIsString($rotated['clientSecret']);
        self::assertNotSame($created['clientSecret'], $rotated['clientSecret']);
        $client = $this->clients()->findClientByPublicId(PublicId::fromString($created['clientId']));
        self::assertNotNull($client);
        self::assertTrue($client->authenticatesWith($rotated['clientSecret']));
        self::assertFalse($client->authenticatesWith($created['clientSecret']));
    }

    public function testPublicAndDeviceClientsHaveNoSecretToRotate(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $public = $this->registerClient($superAdmin, ['name' => 'Player', 'type' => 'public', 'redirectUris' => ['app.baander.player:/callback']]);
        $device = $this->registerClient($superAdmin, ['name' => 'TV', 'type' => 'device']);

        self::assertNull($public['clientSecret']);
        self::assertNull($device['clientSecret']);
        foreach ([$public, $device] as $client) {
            $error = $this->assertJsonResponse($this->authenticatedRequest('POST', self::PATH . '/' . $client['clientId'] . '/rotate-secret', $superAdmin), 409, 'error');
            self::assertSame('no_secret', $error['error']['details']['reason']);
        }
    }

    public function testInvalidRegistrationsAreRejected(): void
    {
        $superAdmin = $this->createSuperAdminUser();

        $this->assertJsonResponse($this->authenticatedRequest('POST', self::PATH, $superAdmin, ['name' => 'X', 'type' => 'first_party']), 422);
        $this->assertJsonResponse($this->authenticatedRequest('POST', self::PATH, $superAdmin, ['name' => '', 'type' => 'device']), 422);
        $error = $this->assertJsonResponse($this->authenticatedRequest('POST', self::PATH, $superAdmin, [
            'name' => 'Player',
            'type' => 'public',
            'redirectUris' => ['http://player.baander.app/callback'],
        ]), 422, 'error');
        self::assertSame('invalid_registration', $error['error']['details']['reason']);
    }

    public function testTheFirstPartyClientCannotBeRotatedOrRevoked(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $spaId = $this->spaClient()->getPublicId()->toString();

        self::assertSame('first_party', $this->listed($superAdmin, $spaId)['type']);
        foreach (['rotate-secret', 'revoke'] as $action) {
            $error = $this->assertJsonResponse($this->authenticatedRequest('POST', self::PATH . '/' . $spaId . '/' . $action, $superAdmin), 409, 'error');
            self::assertSame('protected_client', $error['error']['details']['reason']);
        }
        self::assertFalse($this->spaClient()->isRevoked());
        $this->assertJsonResponse($this->authenticatedRequest('POST', self::PATH . '/AAAAAAAAAAAAAAAAAAAAA/revoke', $superAdmin), 404);
        $this->assertJsonResponse($this->authenticatedRequest('POST', self::PATH . '/not-valid!/revoke', $superAdmin), 404);
    }

    public function testRevocationRevokesTheClientsTokens(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $listener = $this->createTestUser();
        $device = $this->registerClient($superAdmin, ['name' => 'Revoked TV', 'type' => 'device']);
        $other = $this->registerClient($superAdmin, ['name' => 'Kept TV', 'type' => 'device']);
        $tokens = $this->issueTokens($device['clientId'], $listener);
        $otherTokens = $this->issueTokens($other['clientId'], $listener);

        $revoked = $this->assertJsonResponse($this->authenticatedRequest('POST', self::PATH . '/' . $device['clientId'] . '/revoke', $superAdmin), 200, 'data')['data'];

        self::assertTrue($revoked['revoked']);
        self::assertSame([true, true], $this->tokenRevocation($tokens));
        self::assertSame([false, false], $this->tokenRevocation($otherTokens), 'Revocation is scoped to the client.');
        $this->assertJsonResponse($this->authenticatedRequest('POST', self::PATH . '/' . $device['clientId'] . '/revoke', $superAdmin), 200);
    }

    public function testConsoleCommandsManageClientsThroughTheSameUseCases(): void
    {
        $create = $this->command('app:oauth:client:create');
        self::assertSame(Command::SUCCESS, $create->execute([
            'name' => 'Console server',
            '--type' => 'confidential',
            '--redirect-uri' => ['https://console.baander.app/callback'],
        ]), $create->getDisplay());
        self::assertMatchesRegularExpression('/Client ID\s*:?\s*(' . PublicId::PATTERN . ')/', $create->getDisplay());
        preg_match('/Client ID\s*:?\s*(' . PublicId::PATTERN . ')/', $create->getDisplay(), $id);
        preg_match('/Client secret\s*:?\s*([A-Za-z0-9_-]{43})/', $create->getDisplay(), $secret);
        self::assertCount(2, $secret, $create->getDisplay());
        $client = $this->clients()->findClientByPublicId(PublicId::fromString($id[1]));
        self::assertNotNull($client);
        self::assertTrue($client->authenticatesWith($secret[1]));

        $list = $this->command('app:oauth:client:list');
        self::assertSame(Command::SUCCESS, $list->execute([]));
        self::assertStringContainsString($id[1], $list->getDisplay());
        self::assertStringNotContainsString($secret[1], $list->getDisplay());

        $rotate = $this->command('app:oauth:client:rotate-secret');
        self::assertSame(Command::SUCCESS, $rotate->execute(['client-id' => $id[1]]), $rotate->getDisplay());
        preg_match('/Client secret\s*:?\s*([A-Za-z0-9_-]{43})/', $rotate->getDisplay(), $rotated);
        self::assertCount(2, $rotated, $rotate->getDisplay());
        $this->entityManager->clear();
        $client = $this->clients()->findClientByPublicId(PublicId::fromString($id[1]));
        self::assertNotNull($client);
        self::assertTrue($client->authenticatesWith($rotated[1]));

        $revoke = $this->command('app:oauth:client:revoke');
        self::assertSame(Command::SUCCESS, $revoke->execute(['client-id' => $id[1]]), $revoke->getDisplay());
        $this->entityManager->clear();
        self::assertTrue($this->clients()->findClientByPublicId(PublicId::fromString($id[1]))?->isRevoked());

        $spa = $this->command('app:oauth:client:revoke');
        self::assertSame(Command::FAILURE, $spa->execute(['client-id' => $this->spaClient()->getPublicId()->toString()]));
        $invalid = $this->command('app:oauth:client:create');
        self::assertSame(Command::INVALID, $invalid->execute(['name' => 'X', '--type' => 'first_party']));
        $rejected = $this->command('app:oauth:client:create');
        self::assertSame(Command::INVALID, $rejected->execute(['name' => 'Console TV', '--type' => 'device', '--redirect-uri' => ['https://tv.baander.app/callback']]), 'The API answers 422.');

        $json = $this->command('app:oauth:client:create');
        self::assertSame(Command::SUCCESS, $json->execute([
            'name' => 'Scripted server',
            '--type' => 'confidential',
            '--redirect-uri' => ['https://scripted.baander.app/callback'],
            '--json' => true,
        ]), $json->getDisplay());
        $printed = json_decode($json->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($printed);
        $viaApi = $this->registerClient($this->createSuperAdminUser(), ['name' => 'API server', 'type' => 'confidential', 'redirectUris' => ['https://api.baander.app/callback']]);
        self::assertSame(array_keys($viaApi), array_keys($printed), 'The CLI prints the API data.');
        $this->entityManager->clear();
        self::assertTrue($this->clients()->findClientByPublicId(PublicId::fromString($printed['clientId']))?->authenticatesWith($printed['clientSecret']));
    }

    public function testAKeyClientRegistersWithoutASecretOnBothSurfaces(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $jwks = (new SignedClientAssertion())->jwks();

        $created = $this->registerClient($superAdmin, [
            'name' => 'Signing server',
            'type' => 'confidential',
            'redirectUris' => ['https://signing.baander.app/callback'],
            'authMethod' => 'private_key_jwt',
            'jwks' => $jwks,
        ]);

        self::assertSame('confidential', $created['type']);
        self::assertSame('private_key_jwt', $created['authMethod']);
        self::assertNull($created['jwksUri']);
        self::assertNull($created['clientSecret']);
        self::assertSame('private_key_jwt', $this->listed($superAdmin, $created['clientId'])['authMethod']);
        $stored = $this->clients()->findClientByPublicId(PublicId::fromString($created['clientId']));
        self::assertSame($jwks, $stored?->getKeySource()?->jwks());

        $file = $this->jwksFile($jwks);
        $console = $this->command('app:oauth:client:create');
        self::assertSame(Command::SUCCESS, $console->execute([
            'name' => 'Signing server',
            '--type' => 'confidential',
            '--redirect-uri' => ['https://signing.baander.app/callback'],
            '--auth-method' => 'private_key_jwt',
            '--jwks-file' => $file,
            '--json' => true,
        ]), $console->getDisplay());
        $printed = json_decode($console->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($printed);
        $sameFields = static fn (array $client): array => array_diff_key($client, array_flip(['clientId', 'createdAt', 'updatedAt']));
        self::assertSame($sameFields($created), $sameFields($printed), 'The CLI prints the API data.');
    }

    public function testAKeyClientNeedsExactlyOneKeySourceOnBothSurfaces(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $jwks = (new SignedClientAssertion())->jwks();
        $base = ['name' => 'Signing server', 'type' => 'confidential', 'redirectUris' => ['https://signing.baander.app/callback'], 'authMethod' => 'private_key_jwt'];

        foreach (['neither' => [], 'both' => ['jwks' => $jwks, 'jwksUri' => 'https://signing.baander.app/jwks.json']] as $case => $keys) {
            $error = $this->assertJsonResponse($this->authenticatedRequest('POST', self::PATH, $superAdmin, [...$base, ...$keys]), 422, 'error');
            self::assertSame('invalid_registration', $error['error']['details']['reason'], $case);
        }

        $console = ['name' => 'Signing server', '--type' => 'confidential', '--redirect-uri' => ['https://signing.baander.app/callback'], '--auth-method' => 'private_key_jwt'];
        self::assertSame(Command::INVALID, $this->command('app:oauth:client:create')->execute($console));
        self::assertSame(Command::INVALID, $this->command('app:oauth:client:create')->execute([
            ...$console,
            '--jwks-file' => $this->jwksFile($jwks),
            '--jwks-uri' => 'https://signing.baander.app/jwks.json',
        ]));
    }

    public function testReplacingKeysSwitchesTheKeySourceAndRetiresTheOldKeys(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $oldKey = new SignedClientAssertion('old-key');
        $created = $this->registerClient($superAdmin, [
            'name' => 'Signing server',
            'type' => 'confidential',
            'redirectUris' => ['https://signing.baander.app/callback'],
            'authMethod' => 'private_key_jwt',
            'jwks' => $oldKey->jwks(),
        ]);
        $path = self::PATH . '/' . $created['clientId'] . '/keys';
        $connection = $this->entityManager->getConnection();
        $updatedBefore = $connection->fetchOne('SELECT updated_at FROM oauth_clients WHERE public_id = ?', [$created['clientId']]);

        $response = $this->authenticatedRequest('POST', $path, $superAdmin, ['jwksUri' => 'https://signing.baander.app/jwks.json']);
        $replaced = $this->assertJsonResponse($response, 200, 'data')['data'];
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame('https://signing.baander.app/jwks.json', $replaced['jwksUri']);
        self::assertSame('private_key_jwt', $replaced['authMethod']);
        self::assertArrayNotHasKey('clientSecret', $replaced);
        $row = $connection->fetchAssociative('SELECT jwks, jwks_uri, updated_at > ? AS advanced FROM oauth_clients WHERE public_id = ?', [$updatedBefore, $created['clientId']]);
        self::assertSame(['jwks' => null, 'jwks_uri' => 'https://signing.baander.app/jwks.json', 'advanced' => true], $row, 'The stored updated time advances, which retires key sets fetched from the old source.');

        $newKey = new SignedClientAssertion('new-key');
        $replace = $this->command('app:oauth:client:replace-keys');
        self::assertSame(Command::SUCCESS, $replace->execute(['client-id' => $created['clientId'], '--jwks-file' => $this->jwksFile($newKey->jwks())]), $replace->getDisplay());

        $this->entityManager->clear();
        $client = $this->clients()->findClientByPublicId(PublicId::fromString($created['clientId']));
        self::assertNotNull($client);
        self::assertNull($client->getKeySource()?->jwksUri());
        $verifier = static::getContainer()->get(ClientAssertionVerifierInterface::class);
        self::assertInstanceOf(ClientAssertionVerifierInterface::class, $verifier);
        $issuer = static::getContainer()->getParameter('auth.oauth.issuer');
        self::assertIsString($issuer);
        self::assertFalse($verifier->verify($oldKey->create($created['clientId'], $issuer), $client), 'The old key no longer authenticates.');
        self::assertTrue($verifier->verify($newKey->create($created['clientId'], $issuer), $client));
    }

    public function testOnlyActiveKeyClientsGetNewKeysOnBothSurfaces(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $secretClient = $this->registerClient($superAdmin, ['name' => 'Secret server', 'type' => 'confidential', 'redirectUris' => ['https://secret.baander.app/callback']]);
        $keyClient = $this->registerClient($superAdmin, [
            'name' => 'Signing server',
            'type' => 'confidential',
            'redirectUris' => ['https://signing.baander.app/callback'],
            'authMethod' => 'private_key_jwt',
            'jwksUri' => 'https://signing.baander.app/jwks.json',
        ]);
        $keys = ['jwksUri' => 'https://signing.baander.app/rotated.json'];

        $error = $this->assertJsonResponse($this->authenticatedRequest('POST', self::PATH . '/' . $secretClient['clientId'] . '/keys', $superAdmin, $keys), 409, 'error');
        self::assertSame('no_keys', $error['error']['details']['reason']);
        $this->assertJsonResponse($this->authenticatedRequest('POST', self::PATH . '/AAAAAAAAAAAAAAAAAAAAA/keys', $superAdmin, $keys), 404);
        $error = $this->assertJsonResponse($this->authenticatedRequest('POST', self::PATH . '/' . $keyClient['clientId'] . '/rotate-secret', $superAdmin), 409, 'error');
        self::assertSame('no_secret', $error['error']['details']['reason']);
        $error = $this->assertJsonResponse($this->authenticatedRequest('POST', self::PATH . '/' . $keyClient['clientId'] . '/keys', $superAdmin, ['jwksUri' => 'http://signing.baander.app/jwks.json']), 422, 'error');
        self::assertSame('invalid_keys', $error['error']['details']['reason']);

        $console = ['--jwks-uri' => 'https://signing.baander.app/rotated.json'];
        self::assertSame(Command::FAILURE, $this->command('app:oauth:client:replace-keys')->execute(['client-id' => $secretClient['clientId'], ...$console]));
        self::assertSame(Command::FAILURE, $this->command('app:oauth:client:replace-keys')->execute(['client-id' => 'AAAAAAAAAAAAAAAAAAAAA', ...$console]));
        self::assertSame(Command::INVALID, $this->command('app:oauth:client:replace-keys')->execute(['client-id' => $keyClient['clientId']]));
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function registerClient(\App\Auth\Domain\Model\User $superAdmin, array $body): array
    {
        $response = $this->authenticatedRequest('POST', self::PATH, $superAdmin, $body);
        $data = $this->assertJsonResponse($response, 201, 'data')['data'];
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        return $data;
    }

    /** @return array<string, mixed> */
    private function listed(\App\Auth\Domain\Model\User $admin, string $clientId): array
    {
        $clients = $this->assertJsonResponse($this->authenticatedRequest('GET', self::PATH, $admin), 200, 'data')['data'];
        foreach ($clients as $client) {
            if ($client['clientId'] === $clientId) {
                return $client;
            }
        }

        self::fail(sprintf('Client %s is not listed.', $clientId));
    }

    private function spaClient(): Client
    {
        $spaId = static::getContainer()->getParameter('auth.spa_client_id');
        self::assertIsString($spaId);
        $this->entityManager->clear();
        $client = $this->clients()->findClientByPublicId(PublicId::fromString($spaId));
        if ($client === null) {
            $client = Client::create('Bånder SPA', ['http://localhost'], firstParty: true, passwordClient: true, publicId: PublicId::fromString($spaId));
            $this->clients()->saveClient($client);
        }

        return $client;
    }

    private function issueTokens(string $clientId, \App\Auth\Domain\Model\User $user): TokenResponseDTO
    {
        $client = $this->clients()->findClientByPublicId(PublicId::fromString($clientId));
        self::assertNotNull($client);
        $bus = static::getContainer()->get(MessageBusInterface::class);
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $result = $bus->dispatch(new IssueTokenCommand($client->getId(), $user->getId(), 'admin-client-test-jkt', 0, SignInGrant::DeviceCode))
            ->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(TokenResponseDTO::class, $result);

        return $result;
    }

    /** @return array{bool, bool} whether the access token and the refresh token are revoked */
    private function tokenRevocation(TokenResponseDTO $tokens): array
    {
        $connection = $this->entityManager->getConnection();
        $refresh = $connection->fetchAssociative(
            'SELECT r.revoked AS refresh_revoked, a.revoked AS access_revoked FROM oauth_refresh_tokens r JOIN oauth_access_tokens a ON a.id = r.access_token_id WHERE r.token_id = ?',
            [hash('sha256', $tokens->getRefreshToken())],
        );
        self::assertIsArray($refresh);

        return [(bool) $refresh['access_revoked'], (bool) $refresh['refresh_revoked']];
    }

    private function clients(): ClientRepositoryInterface
    {
        $clients = static::getContainer()->get(ClientRepositoryInterface::class);
        self::assertInstanceOf(ClientRepositoryInterface::class, $clients);

        return $clients;
    }

    /** @param array<string, mixed> $jwks */
    private function jwksFile(array $jwks): string
    {
        $path = tempnam(sys_get_temp_dir(), 'baander-jwks-');
        self::assertIsString($path);
        file_put_contents($path, json_encode($jwks, JSON_THROW_ON_ERROR));
        $this->jwksFiles[] = $path;

        return $path;
    }

    private function command(string $name): CommandTester
    {
        return new CommandTester((new Application($this->client->getKernel()))->find($name));
    }
}
