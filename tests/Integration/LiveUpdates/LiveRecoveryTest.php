<?php

declare(strict_types=1);

namespace App\Tests\Integration\LiveUpdates;

use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\AccessTokenEntity;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\ClientEntity;
use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Auth\OAuthAccessTokenJwt;
use App\Tests\Fixtures\Auth\ProductionOAuthKernel;
use App\Tests\Fixtures\Auth\SignedDpopProof;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class LiveRecoveryTest extends TestCase
{
    private const string URI = 'https://baander.app/api/live/recovery';
    private string $directory;
    private ProductionOAuthKernel $kernel;
    private EntityManagerInterface $manager;
    private User $user;
    private ClientEntity $client;
    private SignedDpopProof $proof;
    private string $jwt;

    protected function setUp(): void
    {
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        if (!$url || !getenv('REDIS_URL')) {
            self::markTestSkipped('Disposable PostgreSQL and Redis services are required.');
        }
        $this->directory = sys_get_temp_dir() . '/baander-live-recovery-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory, 0700));
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        self::assertNotFalse($key);
        self::assertTrue(openssl_pkey_export($key, $privateKey));
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        file_put_contents($this->directory . '/private.pem', $privateKey);
        file_put_contents($this->directory . '/public.pem', $details['key']);
        chmod($this->directory . '/private.pem', 0600);
        chmod($this->directory . '/public.pem', 0600);
        $this->kernel = new ProductionOAuthKernel($this->directory, $url);
        $this->kernel->boot();
        $container = $this->kernel->getContainer();
        $manager = $container->get('oauth.acceptance.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $this->manager = $manager;
        $users = $container->get('oauth.acceptance.users');
        self::assertInstanceOf(UserRepositoryInterface::class, $users);
        $this->user = User::createByOperator(new Email('live-' . bin2hex(random_bytes(8)) . '@baander.app'), 'unused-password', 'Live recovery', ['ROLE_USER', 'ROLE_ADMIN']);
        $users->save($this->user);
        $entity = $manager->find(UserEntity::class, $this->user->getId());
        self::assertInstanceOf(UserEntity::class, $entity);
        $this->proof = new SignedDpopProof();
        $this->client = new ClientEntity(new PublicId(), 'Live recovery test', '["https://baander.app/callback"]');
        $token = new AccessTokenEntity((new Uuid())->toString(), $this->client, $entity, scopes: ['library'], expiresAt: new \DateTimeImmutable('+1 hour'));
        $token->setDpopJkt($this->proof->thumbprint());
        $manager->persist($this->client);
        $manager->persist($token);
        $manager->flush();
        $this->jwt = OAuthAccessTokenJwt::sign($privateKey, $token);
    }

    protected function tearDown(): void
    {
        if (isset($this->manager)) {
            $connection = $this->manager->getConnection();
            if (isset($this->user)) {
                $connection->executeStatement('DELETE FROM users WHERE id = ?', [$this->user->getId()->toString()]);
            }
            if (isset($this->client)) {
                $connection->executeStatement('DELETE FROM oauth_clients WHERE id = ?', [$this->client->getId()->toString()]);
            }
            $this->manager->close();
        }
        if (isset($this->kernel)) {
            $this->kernel->shutdown();
        }
        if (isset($this->directory)) {
            (new Filesystem())->remove($this->directory);
        }
    }

    public function testRecoveryUsesProductionDpopFirewallAndFreshDatabaseAuthority(): void
    {
        self::assertSame(403, $this->request()->getStatusCode());
        $proof = $this->proof->create('GET', self::URI, $this->jwt);
        $response = $this->request($proof);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertTrue($response->headers->hasCacheControlDirective('private'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $before = $this->body($response);
        self::assertSame(['version', 'families', 'authorizationFingerprint', 'health'], array_keys($before));
        self::assertContains('admin-users', $before['families']);
        self::assertStringNotContainsString($this->user->getId()->toString(), (string) $response->getContent());
        self::assertSame(403, $this->request($proof)->getStatusCode(), 'Recovery enforces proof replay protection.');

        $this->manager->getConnection()->executeStatement('UPDATE users SET roles = ? WHERE id = ?', ['["ROLE_USER"]', $this->user->getId()->toString()]);
        $response = $this->request($this->proof->create('GET', self::URI, $this->jwt));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $after = $this->body($response);
        self::assertNotContains('admin-users', $after['families']);
        self::assertNotSame($before['authorizationFingerprint'], $after['authorizationFingerprint']);
    }

    private function request(?string $proof = null): Response
    {
        $request = Request::create(self::URI);
        $request->headers->set('Authorization', 'DPoP ' . $this->jwt);
        if ($proof !== null) {
            $request->headers->set('DPoP', $proof);
        }
        $response = $this->kernel->handle($request);
        $this->kernel->terminate($request, $response);

        return $response;
    }

    /** @return array<string, mixed> */
    private function body(Response $response): array
    {
        return json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}
