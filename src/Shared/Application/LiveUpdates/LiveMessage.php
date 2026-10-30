<?php

declare(strict_types=1);

namespace App\Shared\Application\LiveUpdates;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class LiveMessage
{
    public const int MAX_BYTES = 65_536;

    /** @param array<string, mixed> $data */
    private function __construct(private array $data) {}

    /** @param array<string, mixed> $message */
    public static function fromArray(array $message): self
    {
        $keys = ['version', 'type', 'subscriptionId', 'authorizationGeneration', 'authorizationFingerprint', 'families', 'health'];
        if (count($message) !== count($keys) || array_diff($keys, array_keys($message)) !== []) {
            throw new \InvalidArgumentException('Invalid live frame fields.');
        }

        if ($message['version'] !== 1
            || !in_array($message['type'], ['live.ready', 'live.changed', 'live.reset', 'live.health'], true)
            || !self::matches($message['subscriptionId'], '/\A[0-9a-f]{32}\z/D')
            || !self::matches($message['authorizationFingerprint'], '/\A[0-9a-f]{64}\z/D')
            || !self::isGeneration($message['authorizationGeneration'])
        ) {
            throw new \InvalidArgumentException('Invalid live frame identity.');
        }

        $families = $message['families'];
        if (!is_array($families) || !array_is_list($families) || count($families) > count(LiveFamily::cases())) {
            throw new \InvalidArgumentException('Invalid live families.');
        }
        $seen = [];
        foreach ($families as $family) {
            if (!is_string($family) || LiveFamily::tryFrom($family) === null || isset($seen[$family])) {
                throw new \InvalidArgumentException('Unknown or repeated live family.');
            }
            $seen[$family] = true;
        }

        $health = $message['health'];
        if (!is_array($health) || count($health) !== 2
            || !in_array($health['state'] ?? null, ['current', 'degraded'], true)
            || !array_key_exists('oldestPendingAgeMs', $health)
            || ($health['oldestPendingAgeMs'] !== null && (!is_int($health['oldestPendingAgeMs']) || $health['oldestPendingAgeMs'] < 0 || $health['oldestPendingAgeMs'] > 9_007_199_254_740_991))
        ) {
            throw new \InvalidArgumentException('Invalid live pipeline health.');
        }

        return new self($message);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->data;
    }

    private static function matches(mixed $value, string $pattern): bool
    {
        return is_string($value) && preg_match($pattern, $value) === 1;
    }

    private static function isGeneration(mixed $value): bool
    {
        return self::matches($value, '/\A[1-9][0-9]{0,18}\z/D')
            && (strlen($value) < 19 || strcmp($value, '9223372036854775807') <= 0);
    }
}
