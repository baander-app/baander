<?php

declare(strict_types=1);

namespace App\Tests\Functional\Metadata;

use App\Metadata\Infrastructure\Api\Tmdb\TmdbAdapter;
use App\Metadata\Infrastructure\Api\Tvdb\TvdbAdapter;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ProviderKeysEmptyKernelTest extends KernelTestCase
{
    public function testVideoProviderServicesResolveWithoutConfiguredKeys(): void
    {
        $keys = ['TVDB_API_KEY', 'TMDB_BEARER_TOKEN'];
        $environment = [];
        foreach ($keys as $key) {
            $environment[$key] = [getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null];
            putenv($key . '=');
            $_ENV[$key] = '';
            $_SERVER[$key] = '';
        }

        try {
            self::bootKernel();

            $tvdb = self::getContainer()->get('test.tvdb_adapter');
            self::assertInstanceOf(TvdbAdapter::class, $tvdb);
            self::assertFalse($tvdb->isConfigured());
            self::assertFalse(self::getContainer()->get(TmdbAdapter::class)->isConfigured());
        } finally {
            self::ensureKernelShutdown();
            foreach ($environment as $key => [$process, $env, $server]) {
                if ($process === false) {
                    putenv($key);
                } else {
                    putenv($key . '=' . $process);
                }
                unset($_ENV[$key], $_SERVER[$key]);
                if ($env !== null) {
                    $_ENV[$key] = $env;
                }
                if ($server !== null) {
                    $_SERVER[$key] = $server;
                }
            }
        }
    }
}
