<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Messaging;

use App\Shared\Infrastructure\Messaging\WorkerTransportConfiguration;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use SensitiveParameter;
use Symfony\Component\Messenger\Bridge\Redis\Transport\Connection;

final class WorkerTransportConfigurationTest extends TestCase
{
    #[DataProvider('canonicalDsns')]
    public function testAdmittedDsnPreservesConfiguredTransportIdentity(string $dsn): void
    {
        WorkerTransportConfiguration::validate(['MESSENGER_TRANSPORT_DSN' => $dsn]);
        $connection = Connection::fromDsn($dsn, ['stream' => 'messages', 'group' => 'baander', 'consumer' => 'generated-launch-consumer', 'lazy' => true]);
        self::assertSame('messages', new ReflectionProperty($connection, 'stream')->getValue($connection));
        self::assertSame('baander', new ReflectionProperty($connection, 'group')->getValue($connection));
        self::assertSame('generated-launch-consumer', new ReflectionProperty($connection, 'consumer')->getValue($connection));
    }

    /** @return iterable<string,array{string}> */
    public static function canonicalDsns(): iterable
    {
        yield 'pathless' => ['redis://redis.baander.app:6379'];
        yield 'stream' => ['redis://redis.baander.app/messages'];
        yield 'group' => ['redis://redis.baander.app/messages/baander'];
        yield 'TLS credentials' => ['rediss://operator:p%40ss%3A%2C%2Fword@redis.baander.app:6380/messages'];
        yield 'IPv6' => ['redis://[::1]:6379/messages'];
        yield 'connection options' => ['redis://redis.baander.app?dbindex=1&timeout=0.5&read_timeout=2&retry_interval=100'];
        yield 'matching topology options' => ['redis://redis.baander.app?stream=messages&group=baander'];
    }

    #[DataProvider('invalidEnvironments')]
    public function testRejectsAmbiguousOrConflictingTopologyWithoutExposingValues(string $key, #[SensitiveParameter] mixed $value): void
    {
        try {
            WorkerTransportConfiguration::validate([$key => $value]);
            self::fail('Invalid transport configuration must fail.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString($key, $exception->getMessage());
            self::assertStringNotContainsString('private', (string) $exception);
            self::assertNull($exception->getPrevious());
        }
    }

    /** @return iterable<string,array{string,mixed}> */
    public static function invalidEnvironments(): iterable
    {
        foreach ([
            '/other', '/messages/other', '/messages/baander/private', '/messages/baander/private/extra', '/',
            '/messages?stream=private', '/messages/baander?group=private', '/messages/', '/%6dessages', '?consumer=private', '?%63onsumer=private', '?consumer[]=private',
            '?stream=private', '?group=private', '?stream[]=messages', '?group%5Bname%5D=private',
            '?stream=messages&stream=private', '?group=private&group=baander', '?group=baander&%67roup=baander',
            '?host[private]=', '?cluster=1', '?sentinel=private', '?auth=private', '?lazy=0', '?delete_after_ack=0',
            '?unknown=private', '?time.out=1', '?timeout[]=1', '?timeout=private', '?timeout=-1', '?dbindex=1.5',
            '?timeout=1;consumer=private', '?group=%ZZ', '?group', '?group=baander&', '#private',
            ',redis://private.baander.app/messages',
        ] as $suffix) {
            yield 'Messenger ' . $suffix => ['MESSENGER_TRANSPORT_DSN', 'redis://operator:private@redis.baander.app' . $suffix];
        }
        foreach (['', '/', '/0', '/messages', '?timeout=1', '#private'] as $suffix) {
            if ($suffix !== '') {
                yield 'scheduler ' . $suffix => ['REDIS_URL', 'redis://operator:private@redis.baander.app' . $suffix];
            }
        }
        foreach (['', 'in-memory://private', 'redis:///private', 'redis://private:99999', 'redis://private:0', 'redis://private host', 'redis://private%2Cother', 'redis://private/%', 'redis://operator:private@other@redis.baander.app', "redis://private\n"] as $dsn) {
            yield 'invalid URL ' . $dsn => ['MESSENGER_TRANSPORT_DSN', $dsn];
        }
        yield 'wrong type' => ['MESSENGER_TRANSPORT_DSN', ['private']];
    }

    public function testAdmittedSchedulerBasePreservesItsAppendedTransportIdentity(): void
    {
        $base = 'rediss://operator:private%40word@redis.baander.app:6380';
        WorkerTransportConfiguration::validate(['REDIS_URL' => $base]);
        $connection = Connection::fromDsn($base . '/scheduler_occurrences/baander', ['consumer' => 'generated-scheduler-consumer', 'lazy' => true]);
        self::assertSame('scheduler_occurrences', new ReflectionProperty($connection, 'stream')->getValue($connection));
        self::assertSame('baander', new ReflectionProperty($connection, 'group')->getValue($connection));
        self::assertSame('generated-scheduler-consumer', new ReflectionProperty($connection, 'consumer')->getValue($connection));
    }

    public function testResolvedWorkerRequiresTheMessengerUrl(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('MESSENGER_TRANSPORT_DSN');
        WorkerTransportConfiguration::validate(['REDIS_URL' => 'redis://redis.baander.app'], true);
    }

    public function testPartialHostEnvironmentUsesDefaultsButResolvedWorkerRequiresBothUrls(): void
    {
        WorkerTransportConfiguration::validate([]);
        WorkerTransportConfiguration::validate(['REDIS_URL' => 'rediss://operator:private%40word@redis.baander.app:6380']);
        WorkerTransportConfiguration::validate(['REDIS_URL' => 'redis://redis.baander.app', 'MESSENGER_TRANSPORT_DSN' => 'redis://redis.baander.app/messages'], true);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('REDIS_URL');
        WorkerTransportConfiguration::validate(['MESSENGER_TRANSPORT_DSN' => 'redis://redis.baander.app/messages'], true);
    }
}
