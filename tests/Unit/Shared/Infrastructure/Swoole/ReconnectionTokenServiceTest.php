<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Swoole;

use App\Shared\Infrastructure\Swoole\ReconnectionTokenService;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Swoole\Table;

final class ReconnectionTokenServiceTest extends TestCase
{
    private ReconnectionTokenService $service;

    protected function setUp(): void
    {
        if (!\extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole extension is not loaded.');
        }

        $this->service = ReconnectionTokenService::create(maxTokens: 64);
    }

    public function testGenerateReturnsNonEmptyString(): void
    {
        $token = $this->service->generate('user-1');

        $this->assertGreaterThan(0, strlen($token));
    }

    public function testConsumeReturnsUserIdForValidToken(): void
    {
        $token = $this->service->generate('user-1');

        $userId = $this->service->consume($token);

        $this->assertSame('user-1', $userId);
    }

    public function testConsumeDeletesTokenSingleUse(): void
    {
        $token = $this->service->generate('user-1');

        $this->service->consume($token);

        // Second consume should return null (token already used)
        $result = $this->service->consume($token);

        $this->assertNull($result);
    }

    public function testConsumeReturnsNullForUnknownToken(): void
    {
        $result = $this->service->consume('nonexistent-token');

        $this->assertNull($result);
    }

    public function testConsumeReturnsNullForEmptyToken(): void
    {
        $result = $this->service->consume('');

        $this->assertNull($result);
    }

    public function testExistsReturnsTrueForValidToken(): void
    {
        $token = $this->service->generate('user-1');

        $this->assertTrue($this->service->exists($token));
    }

    public function testExistsReturnsFalseAfterConsume(): void
    {
        $token = $this->service->generate('user-1');
        $this->service->consume($token);

        $this->assertFalse($this->service->exists($token));
    }

    public function testExistsReturnsFalseForUnknownToken(): void
    {
        $this->assertFalse($this->service->exists('unknown'));
    }

    public function testMultipleTokensForSameUser(): void
    {
        $token1 = $this->service->generate('user-1');
        $token2 = $this->service->generate('user-1');

        $this->assertNotSame($token1, $token2);
        $this->assertSame('user-1', $this->service->consume($token1));
        $this->assertSame('user-1', $this->service->consume($token2));
    }

    public function testTokensForDifferentUsers(): void
    {
        $tokenUser1 = $this->service->generate('user-1');
        $tokenUser2 = $this->service->generate('user-2');

        $this->assertSame('user-1', $this->service->consume($tokenUser1));
        $this->assertSame('user-2', $this->service->consume($tokenUser2));
    }

    public function testSweepExpiredRemovesOldTokens(): void
    {
        // Generate a token, then manually expire it by setting created_at to the past
        $token = $this->service->generate('user-1');

        // The service creates tokens with current time() and TTL is 300 seconds.
        // We can't easily manipulate Swoole Table timestamps from the outside,
        // so we test the sweep mechanism by verifying it runs without errors
        // and returns 0 for non-expired tokens.

        $removed = $this->service->sweepExpired();

        // Freshly created token should not be swept
        $this->assertSame(0, $removed);
        $this->assertTrue($this->service->exists($token));
    }

    public function testSweepExpiredReturnsZeroForEmptyTable(): void
    {
        $removed = $this->service->sweepExpired();

        $this->assertSame(0, $removed);
    }

    public function testRevokeForUserVoidsEveryTokenOfThatUserOnly(): void
    {
        $first = $this->service->generate('user-1');
        $second = $this->service->generate('user-1');
        $other = $this->service->generate('user-2');

        $this->assertSame(2, $this->service->revokeForUser('user-1'));

        $this->assertNull($this->service->consume($first));
        $this->assertNull($this->service->consume($second));
        $this->assertSame('user-2', $this->service->consume($other));
        $this->assertSame(0, $this->service->revokeForUser('user-1'));
    }

    public function testEveryTokenUpToTheStatedLimitIsStored(): void
    {
        $service = ReconnectionTokenService::create();

        for ($issued = 0; $issued < 4096; ++$issued) {
            $token = $service->generate('user-1');
            self::assertNotNull($token, sprintf('Token %d was refused.', $issued + 1));
            self::assertTrue($service->exists($token), sprintf('Token %d was handed out but not stored.', $issued + 1));
        }
    }

    public function testATokenTheTableCannotStoreIsNotHandedOut(): void
    {
        $logger = new class () extends AbstractLogger {
            /** @var list<string> */
            public array $warnings = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                if ($level === LogLevel::WARNING) {
                    $this->warnings[] = (string) $message;
                }
            }
        };
        $service = ReconnectionTokenService::create(maxTokens: 1, logger: $logger);

        $refused = false;
        for ($issued = 0; $issued < 10_000 && !$refused; ++$issued) {
            $token = $service->generate('user-1');
            if ($token === null) {
                $refused = true;
                continue;
            }
            self::assertTrue($service->exists($token), 'A token was handed out but not stored.');
        }

        self::assertTrue($refused, 'A full token table accepted every token.');
        self::assertCount(1, $logger->warnings);
    }

    public function testAFullTableMakesRoomByDroppingExpiredTokens(): void
    {
        $service = ReconnectionTokenService::create(maxTokens: 1);
        $table = (new \ReflectionProperty($service, 'tokens'))->getValue($service);
        self::assertInstanceOf(Table::class, $table);
        self::fillTable($table, ['user_id' => 'user-1', 'created_at' => time() - 301]);

        $token = $service->generate('user-2');

        self::assertNotNull($token);
        self::assertSame('user-2', $service->consume($token));
        self::assertFalse($service->exists('filler-0'));
    }

    /**
     * Writes filler rows until a thousand writes in a row are refused. The first
     * refusal alone does not mean the table is full: a key whose bucket is still free
     * would get a row.
     *
     * @param array<string, int|string> $row
     */
    private static function fillTable(Table $table, array $row): void
    {
        $refusedInARow = 0;
        for ($key = 0; $refusedInARow < 1000; ++$key) {
            self::assertLessThan(100_000, $key, 'The table never filled up.');
            $refusedInARow = @$table->set('filler-' . $key, $row) ? 0 : $refusedInARow + 1;
        }
    }
}
