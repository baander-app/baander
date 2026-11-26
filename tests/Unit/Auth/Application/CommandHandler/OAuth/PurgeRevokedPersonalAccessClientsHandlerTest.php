<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Application\CommandHandler\OAuth;

use App\Auth\Application\Command\OAuth\PurgeRevokedPersonalAccessClientsCommand;
use App\Auth\Application\CommandHandler\OAuth\PurgeRevokedPersonalAccessClientsHandler;
use App\Auth\Domain\Repository\OAuth\ClientRepositoryInterface;
use PHPUnit\Framework\TestCase;

final class PurgeRevokedPersonalAccessClientsHandlerTest extends TestCase
{
    public function testDeletesTheRevokedPersonalAccessClientsAndReportsHowMany(): void
    {
        $clients = $this->createMock(ClientRepositoryInterface::class);
        $clients->expects($this->once())->method('deleteRevokedPersonalAccessClients')->willReturn(2);

        $result = new PurgeRevokedPersonalAccessClientsHandler($clients)(new PurgeRevokedPersonalAccessClientsCommand());

        self::assertSame(2, $result->clients);
    }
}
