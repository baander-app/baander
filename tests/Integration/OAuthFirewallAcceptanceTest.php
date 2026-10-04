<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\AccessTokenEntity;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\ClientEntity;
use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Kernel;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Auth\SignedDpopProof;
use Defuse\Crypto\Key;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Handler\NullHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Production firewall/authenticators/listeners and actual PostgreSQL/Redis; no TestAuthenticator. */
final class OAuthFirewallAcceptanceTest extends TestCase
{
    private string $directory;
    private string $privateKey;
    private OAuthFirewallAcceptanceKernel $kernel;
    private EntityManagerInterface $manager;
    private User $user;
    private ClientEntity $client;
    private AccessTokenEntity $token;
    private SignedDpopProof $proof;

    protected function setUp(): void
    {
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        if ($url === false || $url === '') {
            self::markTestSkipped('Disposable PostgreSQL and Redis services are required.');
        }
        $this->directory = sys_get_temp_dir() . '/baander-oauth-firewall-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory, 0700));
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        self::assertNotFalse($key);
        self::assertTrue(openssl_pkey_export($key, $privateKey));
        $this->privateKey = $privateKey;
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        self::assertSame(strlen($privateKey), file_put_contents($this->directory . '/private.pem', $privateKey));
        self::assertSame(strlen($details['key']), file_put_contents($this->directory . '/public.pem', $details['key']));
        self::assertTrue(chmod($this->directory . '/private.pem', 0600));
        self::assertTrue(chmod($this->directory . '/public.pem', 0600));
        $this->kernel = new OAuthFirewallAcceptanceKernel($this->directory, $url);
        $this->kernel->boot();
        $container = $this->kernel->getContainer();
        self::assertSame('prod', $container->getParameter('kernel.environment'));
        $manager = $container->get('oauth.acceptance.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $this->manager = $manager;
        $users = $container->get('oauth.acceptance.users');
        self::assertInstanceOf(UserRepositoryInterface::class, $users);
        $this->user = User::register(new Email('oauth-' . bin2hex(random_bytes(8)) . '@baander.app'), 'test-only-password', 'OAuth acceptance user');
        $users->save($this->user);
        $userEntity = $this->manager->find(UserEntity::class, $this->user->getId());
        self::assertInstanceOf(UserEntity::class, $userEntity);
        $this->client = new ClientEntity(new PublicId(), 'Disposable OAuth client', json_encode(['https://baander.app/callback'], JSON_THROW_ON_ERROR));
        $this->proof = new SignedDpopProof();
        $this->token = new AccessTokenEntity((new Uuid())->toString(), $this->client, $userEntity, scopes: ['profile'], expiresAt: new \DateTimeImmutable('+1 hour'));
        $this->token->setDpopJkt($this->proof->thumbprint());
        $this->manager->persist($this->client);
        $this->manager->persist($this->token);
        $this->manager->flush();
    }

    protected function tearDown(): void
    {
        if (isset($this->manager, $this->user, $this->token, $this->client) && $this->manager->isOpen()) {
            $user = $this->manager->find(UserEntity::class, $this->user->getId());
            $this->manager->remove($this->token);
            $this->manager->remove($this->client);
            if ($user !== null) {
                $this->manager->remove($user);
            }
            $this->manager->flush();
            $this->manager->close();
        }
        if (isset($this->kernel)) {
            $this->kernel->shutdown();
        }
        if (isset($this->directory)) {
            (new Filesystem())->remove($this->directory);
        }
        parent::tearDown();
    }

    public function testBoundProofReconstructsActualUserThroughProductionFirewall(): void
    {
        $jwt = $this->accessJwt();
        $response = $this->request($jwt, $this->proof->create('GET', 'https://baander.app/api/auth/me', $jwt));

        self::assertSame(200, $response->getStatusCode(), $response->getContent());
        $data = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($this->user->getEmail(), $data['data']['email']);
    }

    /** @return iterable<string, array{string, int}> */
    public static function invalidPresentations(): iterable
    {
        yield 'missing proof' => ['missing', 403];
        yield 'different proof key' => ['wrong-key', 403];
        yield 'wrong audience' => ['audience', 401];
        yield 'revoked token' => ['revoked', 401];
        yield 'HTTPS proof on HTTP request' => ['scheme', 403];
    }

    #[DataProvider('invalidPresentations')]
    public function testProductionFirewallRejectsInvalidPresentation(string $kind, int $status): void
    {
        if ($kind === 'revoked') {
            $this->token->revoke();
            $this->manager->flush();
        }
        $jwt = $this->accessJwt($kind === 'audience' ? 'https://other.baander.app' : 'https://baander.app');
        $proof = $kind === 'wrong-key' ? new SignedDpopProof() : $this->proof;
        $header = $kind === 'missing' ? null : $proof->create('GET', 'https://baander.app/api/auth/me', $jwt);
        $response = $this->request($jwt, $header, $kind === 'scheme' ? 'http://baander.app/api/auth/me' : 'https://baander.app/api/auth/me');

        self::assertSame($status, $response->getStatusCode(), $response->getContent());
    }

    public function testProofReplayIsRejectedThroughProductionRedisCache(): void
    {
        $jwt = $this->accessJwt();
        $proof = $this->proof->create('GET', 'https://baander.app/api/auth/me', $jwt);

        self::assertSame(200, $this->request($jwt, $proof)->getStatusCode());
        self::assertSame(403, $this->request($jwt, $proof)->getStatusCode());
    }

    private function accessJwt(string $audience = 'https://baander.app'): string
    {
        $claims = [
            'jti' => $this->token->getTokenId(),
            'sub' => $this->user->getId()->toString(),
            'aud' => $audience,
            'client_id' => $this->client->getIdentifier(),
            'scopes' => ['profile'],
            'iat' => time(),
            'nbf' => time() - 1,
            'exp' => time() + 3600,
            'cnf' => ['jkt' => $this->proof->thumbprint()],
        ];
        $body = SignedDpopProof::encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR)) . '.'
            . SignedDpopProof::encode(json_encode($claims, JSON_THROW_ON_ERROR));
        self::assertTrue(openssl_sign($body, $signature, $this->privateKey, OPENSSL_ALGO_SHA256));

        return $body . '.' . SignedDpopProof::encode($signature);
    }

    private function request(string $jwt, ?string $proof, string $uri = 'https://baander.app/api/auth/me'): Response
    {
        $request = Request::create($uri);
        $request->headers->set('Authorization', 'DPoP ' . $jwt);
        if ($proof !== null) {
            $request->headers->set('DPoP', $proof);
        }
        $response = $this->kernel->handle($request);
        $this->kernel->terminate($request, $response);

        return $response;
    }
}

final class OAuthFirewallAcceptanceKernel extends Kernel
{
    public function __construct(private readonly string $directory, private readonly string $databaseUrl)
    {
        parent::__construct('prod', false);
    }

    public function getProjectDir(): string
    {
        return dirname(__DIR__, 2);
    }

    public function getCacheDir(): string
    {
        return $this->directory . '/cache';
    }

    public function getLogDir(): string
    {
        return $this->directory . '/logs';
    }

    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->setAlias('oauth.acceptance.entity_manager', EntityManagerInterface::class)->setPublic(true);
        $container->setAlias('oauth.acceptance.users', UserRepositoryInterface::class)->setPublic(true);
        $container->addCompilerPass(new class($this->directory, $this->databaseUrl) implements CompilerPassInterface {
            public function __construct(private readonly string $directory, private readonly string $databaseUrl)
            {
            }

            public function process(ContainerBuilder $container): void
            {
                foreach (['stdout', 'security', 'messenger'] as $handler) {
                    $container->setDefinition('monolog.handler.' . $handler, new Definition(NullHandler::class));
                }
                $container->setParameter('auth.private_key_path', $this->directory . '/private.pem');
                $container->setParameter('auth.public_key_path', $this->directory . '/public.pem');
                $container->setParameter('auth.encryption_key', Key::createNewRandomKey()->saveToAsciiSafeString());
                $container->setParameter('auth.oauth.issuer', 'https://baander.app');
                $container->setParameter('env(DATABASE_URL)', $this->databaseUrl);
            }
        });
    }
}
