<?php

declare(strict_types=1);

namespace App\Tests\Unit\Worker;

use App\Tests\Fixtures\TwoCommand\LocalOrigin;
use App\Transcode\Infrastructure\Auth\HmacStreamAuthAdapter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TwoCommandOriginTest extends TestCase
{
    public function testRelativeAndSignedAbsoluteUrlsStayOnTheDisposableWebService(): void
    {
        $path = '/api/transcode/video/master.m3u8';
        self::assertSame('http://127.0.0.1:9501' . $path, LocalOrigin::resolve($path));
        $signed = (new HmacStreamAuthAdapter('fixture-secret', LocalOrigin::BASE))->signUrl($path);
        self::assertSame($signed['url'], LocalOrigin::resolve($signed['url']));
        self::assertSame(['-max_redirects', '0', '-i', $signed['url']], LocalOrigin::ffmpegInput($signed['url']));
    }

    public function testFixtureTokenMatchesTheConfiguredLocalResourceServer(): void
    {
        $user = new \App\Auth\Infrastructure\Doctrine\Entity\UserEntity(new \App\Shared\Domain\Model\PublicId(), 'Fixture listener', 'listener@baander.app', 'unused', '');
        $repository = $this->createStub(\Doctrine\ORM\EntityRepository::class);
        $repository->method('findOneBy')->willReturn($user);
        $manager = $this->createStub(\Doctrine\ORM\EntityManagerInterface::class);
        $manager->method('getRepository')->willReturn($repository);
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        self::assertNotFalse($key);
        self::assertTrue(openssl_pkey_export($key, $private));
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        $client = new \App\Tests\Fixtures\WebRuntime\StreamClient($manager, 'listener@baander.app', $private,
            LocalOrigin::BASE . '/api/stream/track', audience: LocalOrigin::BASE);
        $tokens = $this->createStub(\League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface::class);
        $tokens->method('isAccessTokenRevoked')->willReturn(false);
        $validator = new \App\Auth\Infrastructure\Security\OAuth\DpopAwareBearerTokenValidator($tokens, resourceServerUri: LocalOrigin::BASE);
        $validator->setPublicKey(new \League\OAuth2\Server\CryptKey($details['key'], null, false));
        $request = new \Nyholm\Psr7\ServerRequest('GET', LocalOrigin::BASE . '/api/stream/track', ['Authorization' => 'Bearer ' . $client->accessToken()]);
        self::assertIsString($validator->validateAuthorization($request)->getAttribute('oauth_dpop_jkt'));
        $foreign = new \App\Tests\Fixtures\WebRuntime\StreamClient($manager, 'listener@baander.app', $private, LocalOrigin::BASE . '/api/stream/track');
        $foreignRequest = $request->withHeader('Authorization', 'Bearer ' . $foreign->accessToken());
        try {
            $validator->validateAuthorization($foreignRequest);
            self::fail('The fixture default audience must not authenticate against the local resource server.');
        } catch (\League\OAuth2\Server\Exception\OAuthServerException $error) {
            self::assertStringContainsString('audience', $error->getHint());
        }
    }

    public function testHttpAndDecoderRefuseRedirects(): void
    {
        $directory = sys_get_temp_dir() . '/baander-origin-redirect-' . bin2hex(random_bytes(6));
        mkdir($directory);
        $reservation = stream_socket_server('tcp://127.0.0.1:0', $number, $error);
        self::assertIsResource($reservation);
        $address = stream_socket_get_name($reservation, false);
        fclose($reservation);
        file_put_contents($directory . '/router.php', '<?php if ($_SERVER["REQUEST_URI"] === "/ready") { echo "ready"; } elseif ($_SERVER["REQUEST_URI"] === "/redirect") { header("Location: /target", true, 302); } else { file_put_contents(__DIR__."/followed", "yes"); echo "redirect target"; }');
        $server = new \Symfony\Component\Process\Process([PHP_BINARY, '-S', $address, $directory . '/router.php']);
        $server->start();
        try {
            $deadline = microtime(true) + 3;
            do {
                $socket = @stream_socket_client('tcp://' . $address, $number, $error, .05);
                if (is_resource($socket)) { fclose($socket); break; }
                usleep(10000);
            } while (microtime(true) < $deadline);
            self::assertIsResource($socket);
            $url = 'http://' . $address . '/redirect';
            $response = (new \App\Tests\Fixtures\WebRuntime\HttpExchange($url, []))->complete(2);
            self::assertSame(302, $response->status());
            $decoder = new \Symfony\Component\Process\Process(['/usr/bin/ffmpeg', '-v', 'error', '-max_redirects', '0', '-i', $url, '-f', 'null', '-'], timeout: 3);
            $decoder->run();
            self::assertFalse($decoder->isSuccessful());
            self::assertFileDoesNotExist($directory . '/followed');
        } finally {
            $server->stop(1);
            foreach (glob($directory . '/*') ?: [] as $file) { unlink($file); }
            rmdir($directory);
        }
    }

    #[DataProvider('foreignUrls')]
    public function testForeignOrAmbiguousUrlsFailBeforeAnyRequest(string $url): void
    {
        $this->expectException(\RuntimeException::class);
        LocalOrigin::resolve($url);
    }

    /** @return iterable<string,array{string}> */
    public static function foreignUrls(): iterable
    {
        yield 'public origin' => ['https://baander.app/api/transcode/video/master.m3u8'];
        yield 'different port' => ['http://127.0.0.1:9502/api/transcode/video/master.m3u8'];
        yield 'different scheme' => ['https://127.0.0.1:9501/api/transcode/video/master.m3u8'];
        yield 'credentials' => ['http://user:secret@127.0.0.1:9501/api/transcode/video/master.m3u8'];
        yield 'protocol relative' => ['//baander.app/api/transcode/video/master.m3u8'];
        yield 'fragment' => ['http://127.0.0.1:9501/api/transcode/video/master.m3u8#other'];
        yield 'relative segment' => ['master.m3u8'];
    }
}
