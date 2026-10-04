<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Interface\Controller;

use App\Tests\Fixtures\Messaging\MessageCodecFactory;
use App\Auth\Infrastructure\Security\SecurityUser;
use App\Metadata\Application\Command\ExtractAlbumCoverCommand;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Doctrine\Entity\JobMonitorEntity;
use App\Shared\Infrastructure\Messenger\JobIdStamp;
use App\Shared\Infrastructure\Messenger\JobMessageSerializer;
use App\Shared\Infrastructure\Messenger\JobMonitorService;
use App\Shared\Infrastructure\Pagination\CursorCodec;
use App\Shared\Infrastructure\Pagination\CursorPaginator;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use App\Shared\Interface\Controller\JobMonitorController;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class JobMonitorRetryTest extends TestCase
{
    /** @return iterable<string, array{string|null}> */
    public static function queues(): iterable
    {
        yield 'recorded receiver' => ['async'];
        yield 'normal routing' => [null];
    }

    #[DataProvider('queues')]
    public function testRetryRoutesMessageAndAuditsTheNewJobIdentity(?string $queue): void
    {
        $job = $this->failedJob($queue);
        $recorded = new InMemoryTransport();
        $default = new InMemoryTransport();
        $container = new Container();
        $container->set('async', $recorded);
        $container->set('default', $default);
        $bus = new MessageBus([new SendMessageMiddleware(new SendersLocator([
            ExtractAlbumCoverCommand::class => ['default'],
        ], $container))]);
        $controller = $this->controller($job, $bus);
        $user = new SecurityUser(Uuid::generate()->toString(), 'admin@baander.app', '', ['ROLE_ADMIN']);

        $response = $controller->retry($job->getJobId(), $user);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $sent = ($queue === null ? $default : $recorded)->getSent();
        self::assertCount(1, $sent);
        self::assertCount(0, ($queue === null ? $recorded : $default)->getSent());
        self::assertInstanceOf(ExtractAlbumCoverCommand::class, $sent[0]->getMessage());
        $stamp = $sent[0]->last(JobIdStamp::class);
        self::assertNotNull($stamp);
        self::assertSame($stamp->jobId->toString(), $body['data']['newJobId']);
        self::assertNotSame($job->getJobId(), $body['data']['newJobId']);
        self::assertTrue($job->isRetried());
        $audit = json_decode($job->getAuditLog(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('retry', $audit[0]['action']);
        self::assertSame($body['data']['newJobId'], $audit[0]['newJobId']);
        self::assertSame($user->getUserIdentifier(), $audit[0]['userId']);
    }

    public function testDispatchFailureDoesNotMarkOriginalJobRetried(): void
    {
        $job = $this->failedJob(null);
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willThrowException(new \RuntimeException('Transport unavailable'));
        $controller = $this->controller($job, $bus, false);
        $user = new SecurityUser(Uuid::generate()->toString(), 'admin@baander.app', '', ['ROLE_ADMIN']);

        try {
            $controller->retry($job->getJobId(), $user);
            self::fail('Dispatch failure must propagate.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Transport unavailable', $exception->getMessage());
        }

        self::assertFalse($job->isRetried());
        self::assertNull($job->getAuditLog());
    }

    private function failedJob(?string $queue): JobMonitorEntity
    {
        $job = new JobMonitorEntity('original-job', queue: $queue);
        $job->markFailed();
        $job->setData((new JobMessageSerializer(MessageCodecFactory::create()))->serialize(
            new Envelope(new ExtractAlbumCoverCommand(Uuid::generate())),
        ));

        return $job;
    }

    private function controller(JobMonitorEntity $job, MessageBusInterface $bus, bool $expectFlush = true): JobMonitorController
    {
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('findOneBy')->willReturn($job);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);
        $entityManager->expects($expectFlush ? $this->once() : $this->never())->method('flush');
        $encoder = new JsonEncoder();

        return new JobMonitorController(
            new JobMonitorService($entityManager, new CursorPaginator(), $encoder),
            new CursorCodec($encoder),
            $bus,
            new JobMessageSerializer(MessageCodecFactory::create()),
            $this->createStub(RedisClientFactory::class),
        );
    }
}
