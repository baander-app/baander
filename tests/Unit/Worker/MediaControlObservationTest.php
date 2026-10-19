<?php

declare(strict_types=1);

namespace App\Tests\Unit\Worker;

use MediaControlObservation;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once dirname(__DIR__, 2) . '/Fixtures/Worker/MediaControlObservation.php';

final class MediaControlObservationTest extends TestCase
{
    public function testRetiredGenerationMayHaveOneSuccessor(): void
    {
        MediaControlObservation::assertNoOverlap([$this->launch(1, 100), $this->launch(2, 200)]);
        $this->addToAssertionCount(1);
    }

    public function testTransientPredecessorCannotBeHiddenByLaterEmptySnapshot(): void
    {
        $first = $this->launch(1, 100);
        $second = $this->launch(2, 200);
        $second['predecessors'] = [$first['identity']];
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Overlapping native encoder launch');
        MediaControlObservation::assertNoOverlap([$first, $second, $this->launch(3, 300)]);
    }

    public function testSequentialDuplicateCannotBeHiddenByNoOverlap(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Duplicate native encoder launch');
        MediaControlObservation::assertNoOverlap([$this->launch(1, 100), $this->launch(1, 200)]);
    }

    /** @return array{jobId:string,generation:int,outputDirectory:string,identity:array{pid:int,startTicks:string,pidNamespace:string,hostname:string},predecessors:list<array{pid:int,startTicks:string,pidNamespace:string,hostname:string}>} */
    private function launch(int $generation, int $pid): array
    {
        return [
            'jobId' => '12345678-1234-1234-1234-123456789abc',
            'generation' => $generation,
            'outputDirectory' => '/storage/job-12345678-1234-1234-1234-123456789abc/generation-' . $generation,
            'identity' => ['pid' => $pid, 'startTicks' => (string) $pid, 'pidNamespace' => 'pid:[42]', 'hostname' => 'execution'],
            'predecessors' => [],
        ];
    }
}
