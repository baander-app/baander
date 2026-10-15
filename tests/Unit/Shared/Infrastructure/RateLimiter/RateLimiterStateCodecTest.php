<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\RateLimiter;

use App\Shared\Application\Exception\RateLimiterUnavailableException;
use App\Shared\Infrastructure\RateLimiter\RateLimiterStateCodec;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\RateLimiter\Policy\SlidingWindow;
use Symfony\Component\RateLimiter\Policy\Window;

final class RateLimiterStateCodecTest extends TestCase
{
    public function testRoundTripPreservesPolicyStateAndReservationTtl(): void
    {
        $codec = new RateLimiterStateCodec($this->config(), 'test-subject');
        $state = new Window('test-subject', 60, 3);
        $state->add(5);
        $copy = $codec->decode($codec->encode($state));
        self::assertEquals($state, $copy);
        self::assertSame(120, $copy?->getExpirationTime());
        self::assertNull($codec->decode(''));
    }

    /** @return iterable<string, array{string}> */
    public static function corruptions(): iterable
    {
        foreach (['malformed', 'incomplete object', 'wrong class', 'wrong id', 'wrong config', 'wrong interval', 'wrong limit', 'trailing bytes'] as $kind) {
            yield $kind => [$kind];
        }
    }

    #[DataProvider('corruptions')]
    public function testCorruptStateNeverBecomesAnAbsentBucket(string $kind): void
    {
        $config = $this->config();
        $state = match ($kind) {
            'wrong class' => new SlidingWindow('test-subject', 60),
            'wrong id' => new Window('other-subject', 60, 3),
            'wrong interval' => new Window('test-subject', 30, 3),
            'wrong limit' => new Window('test-subject', 60, 4),
            default => new Window('test-subject', 60, 3),
        };
        $payload = json_encode([
            'config' => $kind === 'wrong config' ? str_repeat('0', 64) : hash('sha256', serialize($config)),
            'state' => base64_encode($kind === 'incomplete object'
                ? sprintf('O:%d:"%s":0:{}', strlen(Window::class), Window::class)
                : serialize($state) . ($kind === 'trailing bytes' ? 'extra' : '')),
        ], JSON_THROW_ON_ERROR);
        $this->expectException(RateLimiterUnavailableException::class);
        (new RateLimiterStateCodec($config, 'test-subject'))->decode($kind === 'malformed' ? '{' : $payload);
    }

    /** @return array{id: string, policy: string, limit: int, interval: string} */
    private function config(): array
    {
        return ['id' => 'test', 'policy' => 'fixed_window', 'limit' => 3, 'interval' => '60 seconds'];
    }
}
