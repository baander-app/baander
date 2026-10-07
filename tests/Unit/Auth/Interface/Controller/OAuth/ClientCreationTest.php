<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Interface\Controller\OAuth;

use App\Auth\Application\Command\OAuth\CreatePersonalAccessClientCommand;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Infrastructure\Security\SecurityUser;
use App\Auth\Interface\Controller\OAuth\ClientController;
use App\Auth\Interface\Request\User\CreateClientRequest;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

final class ClientCreationTest extends TestCase
{
    public function testCreationReturnsCreatedResourceForTheCurrentUser(): void
    {
        $owner = Uuid::generate();
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new SecurityUser($owner->toString(), 'owner@baander.app', 'unused'));
        $client = Client::createPersonalAccess('Library player', $owner);
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')
            ->with(self::callback(static fn (object $command): bool => $command instanceof CreatePersonalAccessClientCommand
                && $command->userId->equals($owner)
                && $command->name === 'Library player'))
            ->willReturnCallback(static fn (object $command): Envelope => (new Envelope($command))
                ->with(new HandledStamp($client, 'handler')));

        $response = (new ClientController($security, $bus))->create(new CreateClientRequest('Library player'));

        self::assertSame(201, $response->getStatusCode());
        $body = $response->getContent();
        self::assertIsString($body);
        $data = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        self::assertSame($client->getId()->toString(), $data['data']['uuid']);
        self::assertSame($client->getPublicId()->toString(), $data['data']['publicId']);
        self::assertSame('Library player', $data['data']['name']);
        self::assertTrue($data['data']['personalAccessClient']);
        self::assertFalse($data['data']['confidential']);
        self::assertNull($data['data']['secret']);
    }

    public function testAnonymousCreationDispatchesNothing(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null);
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $response = (new ClientController($security, $bus))->create(new CreateClientRequest('Library player'));

        self::assertSame(401, $response->getStatusCode());
    }
}
