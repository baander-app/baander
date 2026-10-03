<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Interface\Controller\OAuth;

use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Repository\OAuth\ClientRepositoryInterface;
use App\Auth\Infrastructure\Security\SecurityUser;
use App\Auth\Interface\Controller\OAuth\ClientController;
use App\Auth\Interface\Request\User\CreateClientRequest;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ClientCreationTest extends TestCase
{
    public function testCreationReturnsCreatedResourceForThePersistedOwner(): void
    {
        $owner = Uuid::generate();
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new SecurityUser($owner->toString(), 'owner@baander.app', 'unused'));
        $saved = null;
        $repository = $this->createMock(ClientRepositoryInterface::class);
        $repository->expects(self::once())->method('saveClient')->willReturnCallback(static function (Client $client) use (&$saved, $owner): void {
            self::assertTrue($client->isOwnedBy($owner));
            self::assertTrue($client->isPersonalAccessClient());
            self::assertSame('Library player', $client->getName());
            $saved = $client;
        });
        $controller = new ClientController($security, $repository);
        $response = $controller->create(new CreateClientRequest('Library player'));
        self::assertSame(201, $response->getStatusCode());
        self::assertInstanceOf(Client::class, $saved);
        $body = $response->getContent();
        self::assertIsString($body);
        $data = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        self::assertSame($saved->getId()->toString(), $data['data']['uuid']);
        self::assertSame($saved->getPublicId()->toString(), $data['data']['publicId']);
        self::assertSame('Library player', $data['data']['name']);
    }

    public function testAnonymousCreationCannotPersistAClient(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null);
        $repository = $this->createMock(ClientRepositoryInterface::class);
        $repository->expects(self::never())->method('saveClient');
        $controller = new ClientController($security, $repository);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);
        $controller->setTranslator($translator);
        self::assertSame(401, $controller->create(new CreateClientRequest('Library player'))->getStatusCode());
    }
}
