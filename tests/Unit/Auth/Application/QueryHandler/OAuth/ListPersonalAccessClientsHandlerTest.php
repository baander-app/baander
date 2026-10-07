<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Application\QueryHandler\OAuth;

use App\Auth\Application\Query\OAuth\ListPersonalAccessClientsQuery;
use App\Auth\Application\QueryHandler\OAuth\ListPersonalAccessClientsHandler;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Repository\OAuth\ClientRepositoryInterface;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;

final class ListPersonalAccessClientsHandlerTest extends TestCase
{
    public function testReturnsTheRequestingUsersClients(): void
    {
        $owner = Uuid::generate();
        $clients = [Client::createPersonalAccess('A', $owner), Client::createPersonalAccess('B', $owner)];
        $repository = $this->createMock(ClientRepositoryInterface::class);
        $repository->expects(self::once())->method('findPersonalAccessClientsByUser')
            ->with(self::callback(static fn (Uuid $id): bool => $id->equals($owner)))
            ->willReturn($clients);
        $repository->expects(self::never())->method('findPersonalAccessClients');

        self::assertSame($clients, (new ListPersonalAccessClientsHandler($repository))(new ListPersonalAccessClientsQuery($owner)));
    }
}
