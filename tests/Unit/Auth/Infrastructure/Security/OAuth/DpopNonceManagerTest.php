<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Security\OAuth;

use App\Auth\Application\Exception\DpopStoreUnavailableException;
use App\Auth\Infrastructure\Security\OAuth\DpopNonceManager;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use App\Shared\Infrastructure\Redis\RedisPoolExhaustedException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Redis;
use RedisException;
use Symfony\Component\HttpFoundation\JsonResponse;

final class DpopNonceManagerTest extends TestCase
{
    /** @var array<string, array{value: string, ttl: int}> */
    private array $store = [];

    public function testAStoredNonceIsValidOnceAndConsumedByTheRead(): void
    {
        $manager = new DpopNonceManager($this->workingFactory(), nonceTtlSeconds: 300);

        $manager->storeNonce('nonce-a');

        self::assertSame(['value' => '1', 'ttl' => 300], $this->store['dpop:nonce:nonce-a']);
        self::assertTrue($manager->isValid('nonce-a'));
        self::assertFalse($manager->isValid('nonce-a'));
        self::assertFalse($manager->isValid('never-stored'));
    }

    public function testAnIssuedNonceIsStoredBeforeItIsHandedOut(): void
    {
        $manager = new DpopNonceManager($this->workingFactory(), nonceTtlSeconds: 300);

        $nonce = $manager->issueNonce();

        self::assertSame(64, strlen($nonce));
        self::assertSame(['value' => '1', 'ttl' => 300], $this->store['dpop:nonce:' . $nonce]);
        self::assertTrue($manager->isValid($nonce));
        self::assertNotSame($nonce, $manager->issueNonce());
    }

    public function testAnUnreachableStoreIssuesNoNonce(): void
    {
        $cause = new RedisException('Connection refused');
        $manager = new DpopNonceManager($this->failingFactory($cause), logger: $this->loggerWarningOnce());

        try {
            $manager->issueNonce();
            self::fail('No nonce may be issued while the nonce store is unavailable.');
        } catch (DpopStoreUnavailableException $exception) {
            self::assertSame($cause, $exception->getPrevious());
        }

        self::assertSame([], $this->store);
    }

    public function testTheChallengeCarriesTheNonceItStored(): void
    {
        $manager = new DpopNonceManager($this->workingFactory(), nonceTtlSeconds: 300);

        $response = $manager->createChallengeResponse('Nonce required.');

        $nonce = $response->headers->get('DPoP-Nonce');
        self::assertIsString($nonce);
        self::assertSame(400, $response->getStatusCode());
        self::assertArrayHasKey('dpop:nonce:' . $nonce, $this->store);
        self::assertTrue($manager->isValid($nonce));
    }

    public function testTheNextNonceOfACommittedAnswerIsTheOneItStored(): void
    {
        $manager = new DpopNonceManager($this->workingFactory(), nonceTtlSeconds: 300);
        $response = new JsonResponse(['data' => []]);

        $manager->attachNextNonce($response);

        $nonce = $response->headers->get('DPoP-Nonce');
        self::assertIsString($nonce);
        self::assertArrayHasKey('dpop:nonce:' . $nonce, $this->store);
    }

    public function testACommittedAnswerCarriesNoNonceWhenTheStoreIsUnavailable(): void
    {
        $manager = new DpopNonceManager($this->failingFactory(new RedisException('Connection refused')), logger: $this->loggerWarningOnce());
        $response = new JsonResponse(['data' => []]);

        $manager->attachNextNonce($response);

        self::assertFalse($response->headers->has('DPoP-Nonce'));
        self::assertSame([], $this->store);
    }

    public function testAnUnreachableStoreTurnsTheChallengeIntoTheOutageSignal(): void
    {
        $cause = new RedisException('read error on connection to redis.baander.app:6379');
        $manager = new DpopNonceManager($this->failingFactory($cause), logger: $this->loggerWarningOnce());

        try {
            $manager->createChallengeResponse();
            self::fail('No challenge may be issued while the nonce store is unavailable.');
        } catch (DpopStoreUnavailableException $exception) {
            self::assertSame($cause, $exception->getPrevious());
        }

        self::assertSame([], $this->store);
    }

    public function testAnUnreachableStoreTurnsTheValidityCheckIntoTheOutageSignal(): void
    {
        $cause = new RedisException('Connection refused');
        $manager = new DpopNonceManager($this->failingFactory($cause), logger: $this->loggerWarningOnce());

        try {
            $manager->isValid('nonce-a');
            self::fail('A nonce cannot be judged while the nonce store is unavailable.');
        } catch (DpopStoreUnavailableException $exception) {
            self::assertSame($cause, $exception->getPrevious());
        }
    }

    public function testAnExhaustedPoolIsTheOutageSignalToo(): void
    {
        $cause = new RedisPoolExhaustedException(10, 10);
        $manager = new DpopNonceManager($this->failingFactory($cause), logger: $this->loggerWarningOnce());

        $this->expectException(DpopStoreUnavailableException::class);
        $manager->storeNonce('nonce-a');
    }

    public function testAnyOtherFailurePropagatesAsABug(): void
    {
        $cause = new \LogicException('programming error');
        $manager = new DpopNonceManager($this->failingFactory($cause));

        $this->expectExceptionObject($cause);
        $manager->isValid('nonce-a');
    }

    private function workingFactory(): RedisClientFactory
    {
        $store = &$this->store;
        $redis = $this->createStub(Redis::class);
        $redis->method('setex')->willReturnCallback(static function (string $key, int $ttl, mixed $value) use (&$store): bool {
            $store[$key] = ['value' => (string) $value, 'ttl' => $ttl];

            return true;
        });
        $redis->method('getDel')->willReturnCallback(static function (string $key) use (&$store): string|false {
            if (!isset($store[$key])) {
                return false;
            }
            $value = $store[$key]['value'];
            unset($store[$key]);

            return $value;
        });

        $factory = $this->createStub(RedisClientFactory::class);
        $factory->method('borrow')->willReturnCallback(static fn (callable $callback): mixed => $callback($redis));

        return $factory;
    }

    private function failingFactory(\Throwable $cause): RedisClientFactory
    {
        $factory = $this->createStub(RedisClientFactory::class);
        $factory->method('borrow')->willThrowException($cause);

        return $factory;
    }

    private function loggerWarningOnce(): LoggerInterface
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');
        $logger->expects($this->never())->method('error');

        return $logger;
    }
}
