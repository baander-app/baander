<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Domain\Model\ValueObject;

use App\Auth\Domain\Model\OAuth\TokenId;
use App\Shared\Infrastructure\Swoole\WebSocketConnectionRegistry;
use PHPUnit\Framework\TestCase;

final class TokenIdTest extends TestCase
{
    public function testGenerateCreatesRandomId(): void
    {
        $id1 = TokenId::generate();
        $id2 = TokenId::generate();

        $this->assertNotSame($id1->toString(), $id2->toString());
    }

    public function testGenerationDoesNotUseSeededMtRand(): void
    {
        mt_srand(42);
        $first = TokenId::generate()->toString();
        mt_srand(42);
        $second = TokenId::generate()->toString();
        $this->assertNotSame($first, $second);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9\-._~]{80}$/D', $first);
    }

    /**
     * The WebSocket connection table stores the access token id in a column of its own
     * size; Shared Infrastructure must not import the Auth length, so the two are pinned here.
     */
    public function testAGeneratedIdFitsTheWebSocketConnectionTableExactly(): void
    {
        self::assertSame(
            WebSocketConnectionRegistry::ACCESS_TOKEN_ID_BYTES,
            strlen(TokenId::generate()->toString()),
        );
    }

    public function testFromString(): void
    {
        $string = TokenId::generate()->toString();
        $id = TokenId::fromString($string);

        $this->assertSame($string, $id->toString());
    }

    public function testFromStringInvalidThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        TokenId::fromString('not-a-valid-id');
    }

    public function testEquals(): void
    {
        $id = TokenId::generate();
        $same = TokenId::fromString($id->toString());
        $other = TokenId::generate();

        $this->assertTrue($id->equals($same));
        $this->assertFalse($id->equals($other));
    }
}
