<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Swoole\Control;

use App\Shared\Application\Port\ServerControlException;
use App\Shared\Application\Port\ServerControlResult;
use App\Shared\Infrastructure\Swoole\Control\ControlProtocol;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ControlProtocolTest extends TestCase
{
    public function testRequestRoundTrips(): void
    {
        $line = ControlProtocol::encodeRequest('req-1', 'qol.profile.set', ['profile' => 'aggressive']);

        self::assertStringEndsWith("\n", $line);
        self::assertSame(1, substr_count($line, "\n"));
        self::assertSame(
            ['id' => 'req-1', 'op' => 'qol.profile.set', 'payload' => ['profile' => 'aggressive']],
            ControlProtocol::decodeRequest($line),
        );
        self::assertSame([], ControlProtocol::decodeRequest(ControlProtocol::encodeRequest('req-2', 'debug.stats', []))['payload']);
    }

    /** @return iterable<string, array{string}> */
    public static function malformedRequests(): iterable
    {
        yield 'not JSON' => ["not json\n"];
        yield 'JSON list' => ["[1,2]\n"];
        yield 'missing id' => ["{\"op\":\"debug.stats\"}\n"];
        yield 'empty id' => ["{\"id\":\"\",\"op\":\"debug.stats\"}\n"];
        yield 'missing operation' => ["{\"id\":\"r\"}\n"];
        yield 'payload list' => ["{\"id\":\"r\",\"op\":\"debug.stats\",\"payload\":[1]}\n"];
    }

    #[DataProvider('malformedRequests')]
    public function testMalformedRequestIsRejected(string $line): void
    {
        $this->expectException(ServerControlException::class);
        $this->expectExceptionMessage('malformed server control request');

        ControlProtocol::decodeRequest($line);
    }

    public function testResultRoundTripsPerWorkerResultsErrorsAndMissingWorkers(): void
    {
        $result = new ServerControlResult(
            [0 => ['load' => 1.0, 'streams' => []], 2 => ['load' => 0.5, 'streams' => [['id' => 's']]]],
            [1 => 'table full'],
            [3],
        );

        $decoded = ControlProtocol::decodeResponse(ControlProtocol::encodeResult('req-1', $result), 'req-1');

        self::assertEquals($result, $decoded);
        self::assertSame(1.0, $decoded->results[0]['load']);
        self::assertFalse($decoded->isComplete());
    }

    public function testErrorAnswerBecomesTheException(): void
    {
        $this->expectException(ServerControlException::class);
        $this->expectExceptionMessage('unknown server control operation "nope"');

        ControlProtocol::decodeResponse(
            ControlProtocol::encodeError('req-1', 'unknown server control operation "nope"'),
            'req-1',
        );
    }

    public function testRejectionOfAnUnparsedRequestCarriesNoId(): void
    {
        $this->expectExceptionMessage('malformed server control request: boom');

        ControlProtocol::decodeResponse(ControlProtocol::encodeError(null, 'malformed server control request: boom'), 'req-1');
    }

    public function testAnswerToAnotherRequestIsRejected(): void
    {
        $this->expectExceptionMessage('the web server answered a different server control request');

        ControlProtocol::decodeResponse(ControlProtocol::encodeResult('other', new ServerControlResult([0 => true])), 'req-1');
    }

    public function testMalformedAnswerIsRejected(): void
    {
        $this->expectException(ServerControlException::class);

        ControlProtocol::decodeResponse("{\"id\":\"req-1\",\"results\":{\"0\":1},\"errors\":[],\"missing\":[]}\n", 'req-1');
    }
}
