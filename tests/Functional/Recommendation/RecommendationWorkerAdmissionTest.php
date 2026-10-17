<?php

declare(strict_types=1);

namespace App\Tests\Functional\Recommendation;

use App\Recommendation\Application\Command\ExecuteRecommendationJobCommand;
use App\Recommendation\Application\DTO\RecommendationGenerationResult;
use App\Recommendation\Application\Port\RecommendationJobPortInterface;
use App\Tests\Functional\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class RecommendationWorkerAdmissionTest extends TestCase
{
    public function testHttpAdmissionReturnsAPendingJobAndWorkerDeliveryCompletesIt(): void
    {
        $admin = $this->createAdminUser();
        $response = $this->authenticatedRequest('POST', '/api/admin/recommendations/generate', $admin, ['mode' => 'full']);
        $data = $this->assertJsonResponse($response, 202)['data'];
        self::assertSame('pending', $data['status']);
        self::assertSame(RecommendationGenerationResult::EXECUTION_ASYNC, $data['execution']);
        $transport = static::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $executions = array_values(array_filter($transport->getSent(), static fn (Envelope $envelope): bool => $envelope->getMessage() instanceof ExecuteRecommendationJobCommand));
        self::assertCount(1, $executions);
        $message = $executions[0]->getMessage();
        static::getContainer()->get(MessageBusInterface::class)->dispatch(new Envelope($message, [new ReceivedStamp('async')]));
        $job = static::getContainer()->get(RecommendationJobPortInterface::class)->getById($message->jobId);
        self::assertSame('completed', $job->getStatus()->value);
        // Redelivery acknowledges a terminal job without repeating its computation.
        static::getContainer()->get(MessageBusInterface::class)->dispatch(new Envelope($message, [new ReceivedStamp('async')]));
        self::assertSame('completed', $job->getStatus()->value);
    }
}
