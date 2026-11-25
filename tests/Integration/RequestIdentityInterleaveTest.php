<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\Model\OAuth\SignInGrant;
use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Shared\Domain\Model\PublicId;
use App\Tests\Fixtures\Auth\FirstPartyOAuthClient;
use App\Tests\Fixtures\Auth\OAuthAccessTokenJwt;
use App\Tests\Fixtures\Auth\SignedDpopProof;
use App\Tests\Fixtures\Auth\SuspendedRequestOAuthKernel;
use App\Tests\Fixtures\Swoole\CoroutineProbe;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Two users' requests that overlap in one process, through the production firewall and the
 * service pools of the web server, over actual PostgreSQL and Redis. The requests run in a
 * child process (request-identity-interleave.php), in a coroutine each.
 */
final class RequestIdentityInterleaveTest extends TestCase
{
    private string $directory;
    private SuspendedRequestOAuthKernel $kernel;
    private EntityManagerInterface $manager;
    /** @var list<UserEntity> */
    private array $users = [];

    protected function setUp(): void
    {
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        if ($url === false || $url === '') {
            self::markTestSkipped('Disposable PostgreSQL and Redis services are required.');
        }
        $this->directory = sys_get_temp_dir() . '/baander-request-identity-' . (random_bytes(8) |> bin2hex(...));
        self::assertTrue(mkdir($this->directory, 0700));
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        self::assertNotFalse($key);
        self::assertTrue(openssl_pkey_export($key, $privateKey));
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        $this->writeKey('private.pem', $privateKey);
        $this->writeKey('public.pem', $details['key']);
        $this->kernel = new SuspendedRequestOAuthKernel($this->directory, $url);
        $this->kernel->boot();
        $manager = $this->kernel->getContainer()->get('oauth.acceptance.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $this->manager = $manager;
    }

    protected function tearDown(): void
    {
        if (isset($this->manager) && $this->manager->isOpen()) {
            foreach ($this->users as $user) {
                // Access tokens cascade from the user.
                $this->manager->getConnection()->executeStatement('DELETE FROM users WHERE id = ?', [$user->getId()->toString()]);
            }
            $this->manager->close();
        }
        if (isset($this->kernel)) {
            $this->kernel->shutdown();
        }
        if (isset($this->directory)) {
            new Filesystem()->remove($this->directory);
        }
        parent::tearDown();
    }

    public function testARequestSuspendedAfterAuthenticationResumesAsItsOwnUser(): void
    {
        $suspended = $this->session('suspended');
        $completed = $this->session('completed');

        $probe = CoroutineProbe::create([
            dirname(__DIR__) . '/Fixtures/Auth/request-identity-interleave.php',
            $this->directory,
            $suspended['keyPath'],
            $suspended['accessToken'],
            $completed['keyPath'],
            $completed['accessToken'],
        ]);
        $probe->setTimeout(60);
        $probe->run();

        self::assertTrue($probe->isSuccessful(), $probe->getOutput() . $probe->getErrorOutput());
        $answers = json_decode($probe->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(
            ['status' => 200, 'publicId' => $completed['publicId']],
            $answers['completed'],
            'The request that ran to its end while the other was suspended must name its own user.',
        );
        self::assertSame(
            ['status' => 200, 'publicId' => $suspended['publicId']],
            $answers['suspended'],
            'The request that was suspended after authentication must read its own user when it resumes.',
        );
    }

    /**
     * A user with a first-party session bound to a DPoP key of its own.
     *
     * @return array{publicId: string, keyPath: string, accessToken: string}
     */
    private function session(string $name): array
    {
        $user = new UserEntity(new PublicId(), 'Request identity ' . $name, 'identity-' . (random_bytes(8) |> bin2hex(...)) . '@baander.app', 'test-only-unused-password');
        $user->markEmailAsVerified();
        $this->manager->persist($user);
        $this->users[] = $user;
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertNotFalse($key);
        self::assertTrue(openssl_pkey_export($key, $pem));
        $token = FirstPartyOAuthClient::accessToken(
            $this->kernel->getContainer(),
            $this->manager,
            $user,
            SignInGrant::Password,
            new SignedDpopProof($pem)->thumbprint(),
        );
        $this->manager->persist($token);
        $this->manager->flush();

        return [
            'publicId' => $user->getPublicId()->toString(),
            'keyPath' => $this->writeKey('dpop-' . $name . '.pem', $pem),
            'accessToken' => OAuthAccessTokenJwt::sign((string) file_get_contents($this->directory . '/private.pem'), $token),
        ];
    }

    private function writeKey(string $name, string $pem): string
    {
        $path = $this->directory . '/' . $name;
        self::assertSame(strlen($pem), file_put_contents($path, $pem));
        self::assertTrue(chmod($path, 0600));

        return $path;
    }
}
