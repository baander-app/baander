<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Shared\Infrastructure\Messenger\JobMonitorService;
use App\Tests\Functional\TestCase;

final class JobMonitorControllerTest extends TestCase
{
    public function testJobDetailReportsTheWholeRunTimeOfItsCurrentAttempt(): void
    {
        $service = static::getContainer()->get(JobMonitorService::class);
        $attempt = $service->startAttempt('detail-duration-job', 'ExtractAlbumCoverCommand', 'async', null, true);
        $service->markFinished('detail-duration-job', $attempt);
        // A run of a minute and a half; the old detail reported only the seconds part (30).
        $this->entityManager->getConnection()->executeStatement(
            "UPDATE job_monitors SET started_at = finished_at - INTERVAL '90 seconds' WHERE job_id = 'detail-duration-job'",
        );
        $this->entityManager->clear();

        $job = $this->assertJsonResponse(
            $this->authenticatedRequest('GET', '/api/monitor/jobs/detail-duration-job', $this->createAdminUser()),
            200,
            'data',
        )['data'];

        self::assertSame('finished', $job['status']);
        self::assertSame(1, $job['attempt']);
        self::assertEquals(90.0, $job['duration']);
    }
}
