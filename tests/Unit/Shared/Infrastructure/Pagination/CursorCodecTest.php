<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Infrastructure\Pagination;

use App\Shared\Domain\Model\Cursor;
use App\Shared\Domain\Model\CursorDirection;
use App\Shared\Infrastructure\Pagination\CursorCodec;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class CursorCodecTest extends TestCase
{
    private CursorCodec $codec;

    protected function setUp(): void
    {
        $this->codec = new CursorCodec(new JsonEncoder());
    }

    public function testRoundTripWithNextDirectionAndSortValues(): void
    {
        $cursor = Cursor::create(
            CursorDirection::Next,
            ['sort' => 'Some Title', 'id' => '550e8400-e29b-41d4-a716-446655440000'],
        );

        $encoded = $this->codec->encode($cursor);
        $decoded = $this->codec->decode($encoded);

        $this->assertNotNull($decoded);
        $this->assertInstanceOf(Cursor::class, $decoded);
        $this->assertSame(CursorDirection::Next, $decoded->getDirection());
        $this->assertSame($cursor->getValues(), $decoded->getValues());
    }

    public function testRoundTripWithPrevDirection(): void
    {
        $cursor = Cursor::create(
            CursorDirection::Prev,
            ['sort' => 'Another Title', 'id' => '660e8400-e29b-41d4-a716-446655440000'],
        );

        $encoded = $this->codec->encode($cursor);
        $decoded = $this->codec->decode($encoded);

        $this->assertNotNull($decoded);
        $this->assertSame(CursorDirection::Prev, $decoded->getDirection());
        $this->assertSame($cursor->getValues(), $decoded->getValues());
    }

    public function testRoundTripWithEmptyValues(): void
    {
        $cursor = Cursor::create(CursorDirection::Next, []);

        $encoded = $this->codec->encode($cursor);
        $decoded = $this->codec->decode($encoded);

        $this->assertNotNull($decoded);
        $this->assertSame(CursorDirection::Next, $decoded->getDirection());
        $this->assertSame([], $decoded->getValues());
    }

    public function testDecodeInvalidBase64ReturnsNull(): void
    {
        $result = $this->codec->decode('!!!not-valid-base64!!!');

        $this->assertNull($result);
    }

    public function testDecodeValidBase64ButInvalidJsonReturnsNull(): void
    {
        $raw = base64_encode('not valid json');
        $cursor = rtrim(strtr($raw, '+/', '-_'), '=');

        $result = $this->codec->decode($cursor);

        $this->assertNull($result);
    }

    public function testDecodeJsonMissingDirectionKeyReturnsNull(): void
    {
        $json = json_encode(['values' => ['sort' => 'Title']]);
        $cursor = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');

        $result = $this->codec->decode($cursor);

        $this->assertNull($result);
    }

    public function testDecodeJsonMissingValuesKeyReturnsNull(): void
    {
        $json = json_encode(['direction' => 'next']);
        $cursor = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');

        $result = $this->codec->decode($cursor);

        $this->assertNull($result);
    }

    public function testDecodeEmptyStringReturnsNull(): void
    {
        $result = $this->codec->decode('');

        $this->assertNull($result);
    }

    public function testEncodedCursorIsBase64UrlSafe(): void
    {
        $cursor = Cursor::create(CursorDirection::Next, ['sort' => 'a/b+c']);

        $encoded = $this->codec->encode($cursor);

        // base64url must not contain + or /
        $this->assertStringNotContainsString('+', $encoded);
        $this->assertStringNotContainsString('/', $encoded);
        // base64url must not have padding
        $this->assertStringEndsNotWith('=', $encoded);
    }

    public function testDecodeInvalidDirectionValueReturnsNull(): void
    {
        $json = json_encode(['direction' => 'invalid', 'values' => []]);
        $cursor = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');

        $result = $this->codec->decode($cursor);

        $this->assertNull($result);
    }
}
