<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Application\DTO;

use App\Shared\Domain\Model\PublicId;
use App\Transcode\Application\DTO\TranscodeRenditionSet;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TranscodeRenditionSetTest extends TestCase
{
    #[DataProvider('malformedSets')]
    public function testRejectsNoncanonicalOrUnboundedSets(string $encoded): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TranscodeRenditionSet::parse($encoded);
    }

    /** @return iterable<string, array{string}> */
    public static function malformedSets(): iterable
    {
        $a = '0123456789abABCD_-xyz'; $b = '1123456789abABCD_-xyz';
        yield 'missing' => [''];
        yield 'zero generation' => [$a.'.0'];
        yield 'negative generation' => [$a.'.-1'];
        yield 'leading zero' => [$a.'.01'];
        yield 'overflow' => [$a.'.999999999999999999999'];
        yield 'invalid public ID' => ['not-a-public-id.1'];
        yield 'duplicate job' => [$a.'.1,'.$a.'.2'];
        yield 'noncanonical ordering' => [$b.'.1,'.$a.'.1'];
        yield 'trailing comma' => [$a.'.1,'];
        yield 'more than three pairs' => [implode(',', array_map(static fn () => (new PublicId()).'.1', range(1, 4)))];
    }

    public function testCanonicalSetPreservesEachGeneration(): void
    {
        $pairs = [['jobId' => '1123456789abABCD_-xyz', 'generation' => 4], ['jobId' => '0123456789abABCD_-xyz', 'generation' => 7]];
        self::assertSame('0123456789abABCD_-xyz.7,1123456789abABCD_-xyz.4', TranscodeRenditionSet::encode($pairs));
        self::assertSame(array_reverse($pairs), TranscodeRenditionSet::parse(TranscodeRenditionSet::encode($pairs)));
    }
}
