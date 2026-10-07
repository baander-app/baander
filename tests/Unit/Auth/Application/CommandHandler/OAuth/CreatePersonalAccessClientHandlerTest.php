<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Application\CommandHandler\OAuth;

use App\Auth\Application\Command\OAuth\CreatePersonalAccessClientCommand;
use App\Auth\Application\CommandHandler\OAuth\CreatePersonalAccessClientHandler;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Repository\OAuth\ClientRepositoryInterface;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;

final class CreatePersonalAccessClientHandlerTest extends TestCase
{
    public function testCreatesAndPersistsAPublicPersonalAccessClientForTheOwner(): void
    {
        $owner = Uuid::generate();
        $saved = null;
        $repository = $this->createMock(ClientRepositoryInterface::class);
        $repository->expects(self::once())->method('saveClient')
            ->willReturnCallback(static function (Client $client) use (&$saved): void {
                $saved = $client;
            });

        $client = (new CreatePersonalAccessClientHandler($repository))(
            new CreatePersonalAccessClientCommand($owner, 'Library player'),
        );

        self::assertSame($saved, $client);
        self::assertSame('Library player', $client->getName());
        self::assertTrue($client->isOwnedBy($owner));
        self::assertTrue($client->isPersonalAccessClient());
        self::assertFalse($client->isConfidential());
        self::assertNull($client->getSecret());
        self::assertFalse($client->isRevoked());
    }

    public function testBlankNameIsRejectedBeforePersistence(): void
    {
        $repository = $this->createMock(ClientRepositoryInterface::class);
        $repository->expects(self::never())->method('saveClient');

        $this->expectException(\InvalidArgumentException::class);

        (new CreatePersonalAccessClientHandler($repository))(
            new CreatePersonalAccessClientCommand(Uuid::generate(), '   '),
        );
    }
}
