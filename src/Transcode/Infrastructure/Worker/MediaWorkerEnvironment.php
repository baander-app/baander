<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Worker;

use App\Transcode\Application\DTO\MediaWorkerIdentity;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final class MediaWorkerEnvironment
{
    /** @param array<string, string>|null $environment */
    public static function identity(?array $environment = null): MediaWorkerIdentity
    {
        $environment ??= getenv();
        if (($environment['BAANDER_WORKER_ID'] ?? null) !== 'media') {
            throw new \InvalidArgumentException('Media requires private supervisor admission.');
        }
        return new MediaWorkerIdentity(
            $environment['BAANDER_WORKER_NAMESPACE'] ?? '',
            $environment['BAANDER_WORKER_BOOT_ID'] ?? '',
            'media',
            self::positiveInteger($environment['BAANDER_WORKER_GENERATION'] ?? ''),
            self::positiveInteger($environment['BAANDER_WORKER_LEASE_EPOCH'] ?? ''),
        );
    }

    private static function positiveInteger(string $value): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (preg_match('/\A[1-9][0-9]*\z/D', $value) !== 1 || $integer === false) {
            throw new \InvalidArgumentException('Media requires bounded supervisor identity.');
        }
        return $integer;
    }
}
