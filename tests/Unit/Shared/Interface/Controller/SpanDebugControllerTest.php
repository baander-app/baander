<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Interface\Controller;

use App\Shared\Application\Port\ServerDiagnosticsInterface;
use App\Shared\Interface\Controller\SpanDebugController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class SpanDebugControllerTest extends TestCase
{
    /** @return iterable<string, array{string, int}> */
    public static function limits(): iterable
    {
        yield 'requested limit' => ['/api/debug/spans?limit=7', 7];
        yield 'default limit' => ['/api/debug/spans', 100];
    }

    #[DataProvider('limits')]
    public function testSpansReadsTheLimitFromTheRequestObject(string $uri, int $expected): void
    {
        $diagnostics = $this->createMock(ServerDiagnosticsInterface::class);
        $diagnostics->expects(self::once())->method('spans')->with($expected)->willReturn([['operation_name' => 'GET health']]);
        $_GET = ['limit' => '3']; // the request object, not the superglobal, decides

        try {
            $response = (new SpanDebugController($diagnostics))->spans(Request::create($uri));
        } finally {
            $_GET = [];
        }

        self::assertSame([['operation_name' => 'GET health']], json_decode((string) $response->getContent(), true));
    }

    public function testClearEmptiesTheSharedBuffer(): void
    {
        $diagnostics = $this->createMock(ServerDiagnosticsInterface::class);
        $diagnostics->expects(self::once())->method('clearSpans');

        $response = (new SpanDebugController($diagnostics))->clear();

        self::assertSame(['status' => 'cleared'], json_decode((string) $response->getContent(), true));
    }
}
