<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Cache;

use Symfony\Component\Cache\Marshaller\MarshallerInterface;

/**
 * Prefixes every marshalled cache value with an HMAC-SHA256 tag and verifies it before the
 * inner marshaller sees the value. Symfony's default marshaller unserializes with every class
 * allowed, so without the tag a process that can write to Redis could have an object of its
 * choice built from a cache entry. A value whose tag does not verify is refused with a
 * DomainException, which the cache adapters treat as a miss; its bytes never reach unserialize().
 *
 * The tag covers the value, not its key: the marshaller is not given the key. A writer can move
 * an authenticated value to another key, but cannot make one.
 */
final readonly class AuthenticatedMarshaller implements MarshallerInterface
{
    private const string ALGORITHM = 'sha256';
    private const int TAG_BYTES = 32;

    private string $key;

    public function __construct(
        private MarshallerInterface $inner,
        #[\SensitiveParameter] string $secret,
    ) {
        $this->key = hash_hmac(self::ALGORITHM, 'baander.cache.value', $secret, true);
    }

    /**
     * @param array<array-key, mixed> $values
     * @param array<array-key, mixed>|null $failed
     * @return array<array-key, string>
     */
    public function marshall(array $values, ?array &$failed): array
    {
        return array_map($this->tagged(...), $this->inner->marshall($values, $failed));
    }

    public function unmarshall(string $value): mixed
    {
        $payload = substr($value, self::TAG_BYTES);
        if (strlen($value) < self::TAG_BYTES || !hash_equals($this->tag($payload), substr($value, 0, self::TAG_BYTES))) {
            throw new \DomainException('The cache value failed authentication.');
        }

        return $this->inner->unmarshall($payload);
    }

    private function tagged(string $payload): string
    {
        return $this->tag($payload) . $payload;
    }

    private function tag(string $payload): string
    {
        return hash_hmac(self::ALGORITHM, $payload, $this->key, true);
    }
}
