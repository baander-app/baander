<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Cache;

use App\Shared\Infrastructure\Cache\AuthenticatedMarshaller;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Marshaller\DefaultMarshaller;

final class AuthenticatedMarshallerTest extends TestCase
{
    private const string SECRET = 'unit-test-cache-secret-of-32-bytes!';

    protected function setUp(): void
    {
        MarshallerProbe::$built = 0;
    }

    public function testAMarshalledValueRoundTrips(): void
    {
        $marshaller = new AuthenticatedMarshaller(new DefaultMarshaller(), self::SECRET);
        $values = $marshaller->marshall(['jwks' => ['keys' => [['kid' => 'one']]], 'flag' => false, 'empty' => null], $failed);

        self::assertSame([], $failed);
        self::assertSame(['keys' => [['kid' => 'one']]], $marshaller->unmarshall($values['jwks']));
        self::assertFalse($marshaller->unmarshall($values['flag']));
        self::assertNull($marshaller->unmarshall($values['empty']));
    }

    /** @return iterable<string, array{\Closure(string): string}> */
    public static function forgeries(): iterable
    {
        yield 'no tag' => [static fn (string $tagged): string => serialize(new MarshallerProbe())];
        yield 'shorter than a tag' => [static fn (string $tagged): string => 'N;'];
        yield 'payload replaced' => [static fn (string $tagged): string => substr($tagged, 0, 32) . serialize([new MarshallerProbe()])];
        yield 'tag altered' => [static fn (string $tagged): string => (substr($tagged, 0, 1) ^ "\x01") . substr($tagged, 1)];
        yield 'tagged under another secret' => [static function (string $tagged): string {
            $other = new AuthenticatedMarshaller(new DefaultMarshaller(), 'another-cache-secret-of-32-bytes!');

            return $other->marshall(['probe' => new MarshallerProbe()], $failed)['probe'];
        }];
    }

    /** @param \Closure(string): string $forge */
    #[DataProvider('forgeries')]
    public function testAValueWhoseTagDoesNotVerifyIsRefusedBeforeItIsUnserialized(\Closure $forge): void
    {
        $marshaller = new AuthenticatedMarshaller(new DefaultMarshaller(), self::SECRET);
        $tagged = $marshaller->marshall(['probe' => new MarshallerProbe()], $failed)['probe'];
        $forged = $forge($tagged);
        MarshallerProbe::$built = 0;

        try {
            $marshaller->unmarshall($forged);
            self::fail('A forged cache value was accepted.');
        } catch (\DomainException $exception) {
            self::assertSame('The cache value failed authentication.', $exception->getMessage());
        }
        self::assertSame(0, MarshallerProbe::$built);
    }
}

/** Counts every object PHP builds from a serialized string. */
final class MarshallerProbe
{
    public static int $built = 0;

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        ++self::$built;
    }
}
