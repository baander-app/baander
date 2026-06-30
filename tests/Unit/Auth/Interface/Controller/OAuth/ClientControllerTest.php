<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Interface\Controller;

use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Repository\OAuth\ClientRepositoryInterface;
use App\Auth\Infrastructure\Security\SecurityUser;
use App\Auth\Interface\Controller\OAuth\ClientController;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ClientControllerTest extends TestCase
{
    private Security&MockObject $security;
    private ClientRepositoryInterface&MockObject $clientRepository;
    private ClientController $controller;

    protected function setUp(): void
    {
        $this->security = $this->createMock(Security::class);
        $this->clientRepository = $this->createMock(ClientRepositoryInterface::class);
        $this->controller = new ClientController(
            $this->security,
            $this->clientRepository,
        );

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);
        $this->controller->setTranslator($translator);
    }

    // --- IDOR: list filters by user ---

    public function testListReturnsOnlyClientsOwnedByCurrentUser(): void
    {
        $userId = Uuid::v4();
        $user = new SecurityUser(
            id: $userId->toString(),
            email: 'user@example.com',
            password: 'hashed-pw',
        );

        $this->security->method('getUser')->willReturn($user);

        $userClients = [
            Client::createPersonalAccess('My Token A', $userId),
            Client::createPersonalAccess('My Token B', $userId),
        ];

        $this->clientRepository->expects($this->once())
            ->method('findPersonalAccessClientsByUser')
            ->with($this->callback(fn (Uuid $id): bool => $id->equals($userId)))
            ->willReturn($userClients);

        $response = $this->controller->index();

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertCount(2, $data['data']);
    }

    public function testListReturnsEmptyWhenUserHasNoClients(): void
    {
        $userId = Uuid::v4();
        $user = new SecurityUser(
            id: $userId->toString(),
            email: 'user@example.com',
            password: 'hashed-pw',
        );

        $this->security->method('getUser')->willReturn($user);

        $this->clientRepository->method('findPersonalAccessClientsByUser')->willReturn([]);

        $response = $this->controller->index();

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertCount(0, $data['data']);
    }

    public function testListReturnsUnauthorizedWhenNotAuthenticated(): void
    {
        $this->security->method('getUser')->willReturn(null);

        $response = $this->controller->index();

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(401, $response->getStatusCode());

        $this->clientRepository->expects($this->never())->method('findPersonalAccessClientsByUser');
    }

    // --- IDOR: revoke checks ownership ---

    public function testRevokeRejectsWhenClientNotOwnedByUser(): void
    {
        $userIdA = Uuid::v4();
        $userIdB = Uuid::v4();
        $userA = new SecurityUser(
            id: $userIdA->toString(),
            email: 'usera@example.com',
            password: 'hashed-pw',
        );

        $clientOwnedByB = Client::createPersonalAccess('User B Token', $userIdB);

        $this->security->method('getUser')->willReturn($userA);
        $this->clientRepository->method('findClientByPublicId')->willReturn($clientOwnedByB);

        $response = $this->controller->revoke($clientOwnedByB->getPublicId()->toString());

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(404, $response->getStatusCode());

        // Client should NOT be saved (not revoked)
        $this->clientRepository->expects($this->never())->method('saveClient');
    }

    public function testRevokeSucceedsWhenClientOwnedByUser(): void
    {
        $userId = Uuid::v4();
        $user = new SecurityUser(
            id: $userId->toString(),
            email: 'user@example.com',
            password: 'hashed-pw',
        );

        $client = Client::createPersonalAccess('My Token', $userId);

        $this->security->method('getUser')->willReturn($user);
        $this->clientRepository->method('findClientByPublicId')->willReturn($client);
        $this->clientRepository->expects($this->once())->method('saveClient');

        $response = $this->controller->revoke($client->getPublicId()->toString());

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testRevokeReturnsNotFoundWhenClientDoesNotExist(): void
    {
        $userId = Uuid::v4();
        $user = new SecurityUser(
            id: $userId->toString(),
            email: 'user@example.com',
            password: 'hashed-pw',
        );

        $nonexistentPublicId = new PublicId();

        $this->security->method('getUser')->willReturn($user);
        $this->clientRepository->method('findClientByPublicId')->willReturn(null);

        $response = $this->controller->revoke($nonexistentPublicId->toString());

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testRevokeReturnsNotFoundWhenClientHasNoOwner(): void
    {
        $userId = Uuid::v4();
        $user = new SecurityUser(
            id: $userId->toString(),
            email: 'user@example.com',
            password: 'hashed-pw',
        );

        // Client created without a userId (legacy or system client)
        $orphanClient = Client::createPersonalAccess('System Token');
        $this->assertFalse($orphanClient->isOwnedBy($userId));

        $this->security->method('getUser')->willReturn($user);
        $this->clientRepository->method('findClientByPublicId')->willReturn($orphanClient);

        $response = $this->controller->revoke($orphanClient->getPublicId()->toString());

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testRevokeReturnsUnauthorizedWhenNotAuthenticated(): void
    {
        $this->security->method('getUser')->willReturn(null);

        $response = $this->controller->revoke('some-id');

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(401, $response->getStatusCode());

        $this->clientRepository->expects($this->never())->method('findClientByPublicId');
    }
}
