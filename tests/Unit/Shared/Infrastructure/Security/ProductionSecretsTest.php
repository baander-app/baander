<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Security;

use App\Kernel;
use App\Shared\Infrastructure\Security\ProductionSecrets;
use App\Shared\Infrastructure\Security\ProductionSecretsRefusedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Dotenv\Dotenv;

final class ProductionSecretsTest extends TestCase
{
    private const array PRIVATE = [
        'APP_SECRET' => 'f3b6c1e0a9d84b27a1c5e2f90d7b3a6481c0e5d2b9f7a3c6',
        'STREAM_URL_HMAC_SECRET' => '7c2e9a41d0b85f36e1a4c9d27b0f8e3a5d6c1b9e4f2a07d3',
        'AUTH_TOTP_ENCRYPTION_KEY' => 'q83vEjRWeJASNFZ4kKu83RI0VniQq7zeEjRWeJCrvN4=',
        'REDIS_PASSWORD' => '5d1f0c7e9a2b4c68e3f1a0d9b7c2e4f6',
        'DATABASE_URL' => 'postgresql://baander:9e4c1a7f3b2d0e6c@pooler:6432/baander?serverVersion=18&charset=utf8',
    ];

    /** The Web Push private key that `.env` committed until 2026-11-21. */
    private const string COMMITTED_VAPID_PRIVATE_KEY = 'XcgrUfkAEzytV6BH7A62gvcoNNBbBGpwK6PEcQe2wUw';

    public function testPrivateValuesOfAtLeast32BytesAreAccepted(): void
    {
        self::assertSame([], new ProductionSecrets()->problems($this->lookup([])));
    }

    /** @return iterable<string, array{string, ?string, string}> */
    public static function refusedValues(): iterable
    {
        yield 'unset APP_SECRET' => ['APP_SECRET', null, 'APP_SECRET is not set'];
        yield 'empty APP_SECRET' => ['APP_SECRET', '', 'APP_SECRET is not set'];
        yield 'committed APP_SECRET' => ['APP_SECRET', 'change_me_in_production', 'APP_SECRET is a committed default'];
        yield 'committed development APP_SECRET' => ['APP_SECRET', 'aba6bf1488f290aaaf6f5a8371bd0f6c', 'APP_SECRET is a committed default'];
        yield 'committed stream secret' => ['STREAM_URL_HMAC_SECRET', 'dev-secret-change-me-in-production', 'STREAM_URL_HMAC_SECRET is a committed default'];
        yield 'example stream secret' => ['STREAM_URL_HMAC_SECRET', 'change-me-to-a-random-64-char-secret-in-production', 'STREAM_URL_HMAC_SECRET is a committed default'];
        yield 'committed TOTP key' => ['AUTH_TOTP_ENCRYPTION_KEY', 'vhDAUukeCl95XW2ZyaTcQbB67hk3cv0yLDiezobAarA=', 'AUTH_TOTP_ENCRYPTION_KEY is a committed default'];
        yield 'placeholder stream secret' => ['STREAM_URL_HMAC_SECRET', 'replace-with-a-separate-private-random-value', 'STREAM_URL_HMAC_SECRET is a placeholder'];
        yield 'short APP_SECRET' => ['APP_SECRET', str_repeat('a', 31), 'APP_SECRET is shorter than 32 bytes'];
        yield 'short TOTP key' => ['AUTH_TOTP_ENCRYPTION_KEY', base64_encode(str_repeat("\x01", 31)), 'AUTH_TOTP_ENCRYPTION_KEY is shorter than 32 bytes'];
        yield 'TOTP key that is not base64' => ['AUTH_TOTP_ENCRYPTION_KEY', str_repeat('!', 44), 'AUTH_TOTP_ENCRYPTION_KEY is not base64'];
        yield 'unset Redis password' => ['REDIS_PASSWORD', null, 'REDIS_PASSWORD is not set'];
        yield 'committed Redis password' => ['REDIS_PASSWORD', 'baander', 'REDIS_PASSWORD is a committed default'];
        yield 'unset database URL' => ['DATABASE_URL', null, 'DATABASE_URL is not set'];
        yield 'database URL without a password' => ['DATABASE_URL', 'postgresql://baander@pooler:6432/baander', 'DATABASE_URL has no password'];
        yield 'database URL with the committed password' => ['DATABASE_URL', 'postgresql://baander:baander@pooler:6432/baander?serverVersion=18', 'DATABASE_URL uses a committed default password'];
        yield 'control database URL with the committed password' => ['DATABASE_CONTROL_URL', 'postgresql://baander_control:baander@pooler:6432/baander_control', 'DATABASE_CONTROL_URL uses a committed default password'];
        yield 'committed Web Push private key' => ['VAPID_PRIVATE_KEY', self::COMMITTED_VAPID_PRIVATE_KEY, 'VAPID_PRIVATE_KEY is a committed key'];
    }

    public function testOptionalCredentialsMayBeUnsetOrPrivate(): void
    {
        $lookup = $this->lookup([
            'DATABASE_CONTROL_URL' => 'postgresql://baander_control:4b8e2d6f0a1c3e5d@pooler:6432/baander_control',
            'VAPID_PRIVATE_KEY' => 'k3Jt0vQx8bN2mZr5sWy7uA1cE4gH6iL9oP0qR2tU5vX',
        ]);

        self::assertSame([], new ProductionSecrets()->problems($lookup));
    }

    #[DataProvider('refusedValues')]
    public function testRefusedValueNamesTheVariableWithoutPrintingIt(string $variable, ?string $value, string $problem): void
    {
        $problems = new ProductionSecrets()->problems($this->lookup([$variable => $value]));

        self::assertCount(1, $problems);
        self::assertStringStartsWith($problem, $problems[0]);
        if ($value !== null && $value !== '') {
            self::assertStringNotContainsString($value, $problems[0]);
        }
    }

    public function testEveryValueCommittedInTheProjectEnvironmentFilesIsRefused(): void
    {
        $project = dirname(__DIR__, 5);
        $committed = [];
        foreach (['.env.dev', '.env.example'] as $file) {
            $values = new Dotenv()->parse((string) file_get_contents($project . '/' . $file), $file);
            foreach ([...ProductionSecrets::VARIABLES, ...ProductionSecrets::CREDENTIAL_VARIABLES] as $variable) {
                if (($values[$variable] ?? '') !== '') {
                    $committed[] = [$variable, $values[$variable]];
                }
            }
        }

        self::assertNotEmpty($committed);
        foreach ($committed as [$variable, $value]) {
            $problems = new ProductionSecrets()->problems($this->lookup([$variable => $value]));
            self::assertCount(1, $problems, $variable . ' from a committed environment file is accepted');
        }
    }

    public function testTheProductionKernelRefusesToBootWithACommittedDefault(): void
    {
        $saved = [$_ENV['STREAM_URL_HMAC_SECRET'] ?? null, $_SERVER['STREAM_URL_HMAC_SECRET'] ?? null];
        $_ENV['STREAM_URL_HMAC_SECRET'] = $_SERVER['STREAM_URL_HMAC_SECRET'] = 'dev-secret-change-me-in-production';
        $kernel = new Kernel('prod', false);
        try {
            $kernel->boot();
            self::fail('The production kernel booted with a committed stream secret.');
        } catch (ProductionSecretsRefusedException $refused) {
            self::assertStringContainsString('STREAM_URL_HMAC_SECRET', $refused->getMessage());
        } finally {
            [$_ENV['STREAM_URL_HMAC_SECRET'], $_SERVER['STREAM_URL_HMAC_SECRET']] = $saved;
            if ($saved[0] === null) {
                unset($_ENV['STREAM_URL_HMAC_SECRET']);
            }
            if ($saved[1] === null) {
                unset($_SERVER['STREAM_URL_HMAC_SECRET']);
            }
        }
    }

    /**
     * @param array<string, ?string> $overrides
     * @return \Closure(string): ?string
     */
    private function lookup(array $overrides): \Closure
    {
        $values = array_replace(self::PRIVATE, $overrides);

        return static fn (string $name): ?string => $values[$name] ?? null;
    }
}
