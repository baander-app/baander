<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\LiveUpdates;

use App\Shared\Application\LiveUpdates\LiveFamily;
use App\Shared\Application\LiveUpdates\LiveMessage;
use App\Shared\Application\LiveUpdates\LiveSubscription;
use PHPUnit\Framework\TestCase;

final class LiveProtocolTest extends TestCase
{
    public function testServerAndBrowserShareTheFiniteFamilyVocabulary(): void
    {
        self::assertSame($this->fixtures()['families'], array_column(LiveFamily::cases(), 'value'));
    }

    public function testValidFramesRoundTripWithoutAddingPrivateScopeData(): void
    {
        foreach ($this->fixtures()['valid'] as $frame) {
            self::assertSame($frame, LiveMessage::fromArray($frame)->toArray());
        }
    }

    public function testMalformedFramesAreRejected(): void
    {
        foreach ($this->fixtures()['invalid'] as $frame) {
            try {
                LiveMessage::fromArray($frame);
                self::fail('Malformed live frame was accepted: ' . json_encode($frame, JSON_THROW_ON_ERROR));
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testSubscriptionDoesNotAcceptClientSelectedAudiences(): void
    {
        self::assertInstanceOf(LiveSubscription::class, LiveSubscription::fromArray(['type' => 'live.subscribe', 'version' => 1]));
        $this->expectException(\InvalidArgumentException::class);
        LiveSubscription::fromArray(['type' => 'live.subscribe', 'version' => 1, 'families' => ['admin-users']]);
    }

    /** @return array{families: list<string>, valid: list<array<string, mixed>>, invalid: list<array<string, mixed>>} */
    private function fixtures(): array
    {
        return file_get_contents(__DIR__ . '/../../../Fixtures/LiveUpdates/protocol.json')
            |> (static fn(string $json): array => json_decode($json, true, flags: JSON_THROW_ON_ERROR));
    }
}
