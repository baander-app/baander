<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\AccessTokenEntity;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\ClientEntity;
use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Notification\Infrastructure\Doctrine\Entity\PushSubscriptionEntity;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Auth\OAuthAccessTokenJwt;
use App\Tests\Fixtures\Auth\ProductionOAuthKernel;
use App\Tests\Fixtures\Auth\SignedDpopProof;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;

/** Production OAuth/DPoP and actual PostgreSQL deletion, without push delivery. */
final class PushUnsubscribeFirewallTest extends TestCase
{
    private static string $directory;
    private static string $privateKey;
    private ProductionOAuthKernel $kernel;
    private EntityManagerInterface $manager;
    private UserRepositoryInterface $users;
    /** @var list<UserEntity|PushSubscriptionEntity|ClientEntity|AccessTokenEntity> */
    private array $entities = [];

    public static function setUpBeforeClass(): void
    {
        self::$directory = sys_get_temp_dir() . '/baander-push-firewall-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir(self::$directory, 0700));
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        self::assertNotFalse($key);
        self::assertTrue(openssl_pkey_export($key, $privateKey));
        self::$privateKey = $privateKey;
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        self::assertSame(strlen($privateKey), file_put_contents(self::$directory . '/private.pem', $privateKey));
        self::assertSame(strlen($details['key']), file_put_contents(self::$directory . '/public.pem', $details['key']));
        self::assertTrue(chmod(self::$directory . '/private.pem', 0600));
        self::assertTrue(chmod(self::$directory . '/public.pem', 0600));
    }

    public static function tearDownAfterClass(): void
    {
        (new Filesystem())->remove(self::$directory);
    }

    protected function setUp(): void
    {
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        if ($url === false || $url === '') {
            self::markTestSkipped('Run with disposable PostgreSQL and Redis.');
        }
        $this->kernel = new ProductionOAuthKernel(self::$directory, $url);
        $this->kernel->boot();
        $manager = $this->kernel->getContainer()->get('oauth.acceptance.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $this->manager = $manager;
        $users = $this->kernel->getContainer()->get('oauth.acceptance.users');
        self::assertInstanceOf(UserRepositoryInterface::class, $users);
        $this->users = $users;
    }

    protected function tearDown(): void
    {
        if (isset($this->manager) && $this->manager->isOpen()) {
            $this->manager->clear();
            foreach (array_reverse($this->entities) as $entity) {
                $stored = $this->manager->find($entity::class, $entity->getId());
                if ($stored !== null) {
                    $this->manager->remove($stored);
                }
            }
            $this->manager->flush();
            $this->manager->close();
        }
        if (isset($this->kernel)) {
            $this->kernel->shutdown();
        }
        parent::tearDown();
    }

    /** @return iterable<string, array{string, bool, int}> */
    public static function actors(): iterable
    {
        yield 'owner known' => ['owner', true, 0];
        yield 'owner missing' => ['owner', false, 1];
        yield 'unrelated known' => ['unrelated', true, 1];
        yield 'unrelated missing' => ['unrelated', false, 1];
        yield 'admin unrelated' => ['admin', true, 1];
        yield 'anonymous' => ['anonymous', true, 1];
    }

    #[DataProvider('actors')]
    public function testOnlyOwnerCanRemoveEndpointWithoutAnExistenceOracle(string $actor, bool $known, int $remaining): void
    {
        $owner = $this->createUser('owner');
        $endpoint = 'https://push.baander.app/owner-' . bin2hex(random_bytes(8));
        $subscription = new PushSubscriptionEntity($owner, $endpoint, 'public-key', 'auth-key', 'aes128gcm');
        $this->manager->persist($subscription);
        $this->entities[] = $subscription;
        $jwt = null;
        $proof = new SignedDpopProof();
        if ($actor !== 'anonymous') {
            $user = $actor === 'owner' ? $owner : $this->createUser($actor);
            $client = new ClientEntity(new PublicId(), 'Push acceptance', json_encode(['https://baander.app/callback'], JSON_THROW_ON_ERROR));
            $token = new AccessTokenEntity((new Uuid())->toString(), $client, $user, scopes: ['library'], expiresAt: new \DateTimeImmutable('+1 hour'));
            $token->setDpopJkt($proof->thumbprint());
            $this->manager->persist($client);
            $this->manager->persist($token);
            $this->entities[] = $client;
            $this->entities[] = $token;
            $this->manager->flush();
            $jwt = OAuthAccessTokenJwt::sign(self::$privateKey, $token);
        } else {
            $this->manager->flush();
        }
        $this->manager->clear();
        $uri = 'https://baander.app/api/push/subscribe';
        $request = Request::create($uri, 'DELETE', content: json_encode([
            'endpoint' => $known ? $endpoint : 'https://push.baander.app/missing',
        ], JSON_THROW_ON_ERROR));
        $request->headers->set('Content-Type', 'application/json');
        if ($jwt !== null) {
            $request->headers->set('Authorization', 'DPoP ' . $jwt);
            $request->headers->set('DPoP', $proof->create('DELETE', $uri, $jwt));
        }
        $response = $this->kernel->handle($request);
        $this->kernel->terminate($request, $response);
        self::assertSame($actor === 'anonymous' ? 401 : 204, $response->getStatusCode());
        if ($actor !== 'anonymous') {
            self::assertSame('', (string) $response->getContent());
        }
        self::assertSame($remaining, (int) $this->manager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM push_subscriptions WHERE endpoint = :endpoint',
            ['endpoint' => $endpoint],
        ));
    }

    private function createUser(string $actor): UserEntity
    {
        $user = User::createByOperator(
            new Email('push-' . $actor . '-' . bin2hex(random_bytes(8)) . '@baander.app'),
            'unused-password', 'Push acceptance',
            $actor === 'admin' ? ['ROLE_USER', 'ROLE_ADMIN'] : ['ROLE_USER'],
        );
        $this->users->save($user);
        $entity = $this->manager->find(UserEntity::class, $user->getId());
        self::assertInstanceOf(UserEntity::class, $entity);
        $this->entities[] = $entity;
        return $entity;
    }
}
