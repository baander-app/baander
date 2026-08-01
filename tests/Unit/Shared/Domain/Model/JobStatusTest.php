<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\Model;

use App\Shared\Domain\Model\JobStatus;
use PHPUnit\Framework\TestCase;

final class JobStatusTest extends TestCase
{
    public function testAllCasesHaveCorrectStringValues(): void
    {
        $this->assertSame('queued', JobStatus::Queued->value);
        $this->assertSame('running', JobStatus::Running->value);
        $this->assertSame('finished', JobStatus::Finished->value);
        $this->assertSame('failed', JobStatus::Failed->value);
        $this->assertSame('cancelled', JobStatus::Cancelled->value);
    }

    public function testFromQueuedReturnsQueuedCase(): void
    {
        $status = JobStatus::from('queued');

        $this->assertSame(JobStatus::Queued, $status);
    }

    public function testFromCancelledReturnsCancelledCase(): void
    {
        $status = JobStatus::from('cancelled');

        $this->assertSame(JobStatus::Cancelled, $status);
    }

    public function testExistingCasesAreUnchanged(): void
    {
        $this->assertSame(JobStatus::Running, JobStatus::from('running'));
        $this->assertSame(JobStatus::Finished, JobStatus::from('finished'));
        $this->assertSame(JobStatus::Failed, JobStatus::from('failed'));
    }

    public function testEnumHasExactlyFiveCases(): void
    {
        $cases = JobStatus::cases();

        $this->assertCount(5, $cases);
    }
}
