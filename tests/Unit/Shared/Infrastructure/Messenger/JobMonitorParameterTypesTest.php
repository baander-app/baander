<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Messenger;

use App\Shared\Infrastructure\Messenger\JobMonitorService;
use App\Shared\Infrastructure\Pagination\CursorPaginator;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class JobMonitorParameterTypesTest extends TestCase
{
    public function testBooleanAndNullJobDataAreBoundWithTheirDatabaseTypes(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->exactly(2))->method('update')->willReturnCallback(
            static function (string $table, array $data, array $criteria, array $types = []): int {
                self::assertSame('job_monitors', $table);
                self::assertSame(['job_id' => 'job-1'], $criteria);
                self::assertSame(ParameterType::BOOLEAN, $types['data_truncated'] ?? null);
                self::assertSame($data['data'] === null ? ParameterType::NULL : ParameterType::STRING, $types['data'] ?? null);
                return 1;
            },
        );
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $service = new JobMonitorService($em, new CursorPaginator(), new JsonEncoder());
        $service->setData('job-1', '{}', false);
        $service->setData('job-1', null, true);
    }
}
