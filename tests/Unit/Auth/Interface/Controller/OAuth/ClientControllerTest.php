<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Interface\Controller\OAuth;

use App\Auth\Application\Command\OAuth\RevokeClientCommand;
use App\Auth\Application\Exception\ClientNotFoundException;
use App\Auth\Application\Query\OAuth\ListPersonalAccessClientsQuery;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Infrastructure\Security\SecurityUser;
use App\Auth\Interface\Controller\OAuth\ClientController;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ClientControllerTest extends TestCase
{
    private Security&Stub $security;
    private MessageBusInterface&MockObject $bus;
    private ClientController $controller;

    protected function setUp(): void
    {
        $this->security = $this->createStub(Security::class);
        $this->bus = $this->createMock(MessageBusInterface::class);
        $this->controller = new ClientController($this->security, $this->bus);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);
        $this->controller->setTranslator($translator);
    }

    public function testListDispatchesQueryForTheCurrentUserAndMapsResult(): void
    {
        $userId = $this->authenticate();
        $clients = [Client::createPersonalAccess('My Token A', $userId), Client::createPersonalAccess('My Token B', $userId)];
        $this->bus->expects(self::once())->method('dispatch')
            ->with(self::callback(static fn (object $query): bool => $query instanceof ListPersonalAccessClientsQuery
                && $query->userId->equals($userId)))
            ->willReturnCallback(static fn (object $query): Envelope => self::handled($query, $clients));

        $data = $this->decode($this->controller->index(), 200);

        self::assertCount(2, $data['data']);
        self::assertSame($clients[0]->getPublicId()->toString(), $data['data'][0]['publicId']);
        self::assertTrue($data['data'][0]['personalAccessClient']);
    }

    public function testListReturnsEmptyCollection(): void
    {
        $this->authenticate();
        $this->bus->expects(self::once())->method('dispatch')->willReturnCallback(static fn (object $query): Envelope => self::handled($query, []));

        self::assertSame([], $this->decode($this->controller->index(), 200)['data']);
    }

    public function testAnonymousRequestsDispatchNothing(): void
    {
        $this->security->method('getUser')->willReturn(null);
        $this->bus->expects(self::never())->method('dispatch');

        self::assertSame(401, $this->controller->index()->getStatusCode());
        self::assertSame(401, $this->controller->revoke((new PublicId())->toString())->getStatusCode());
    }

    public function testRevokeDispatchesOwnerScopedCommand(): void
    {
        $userId = $this->authenticate();
        $publicId = new PublicId();
        $this->bus->expects(self::once())->method('dispatch')
            ->with(self::callback(static fn (object $command): bool => $command instanceof RevokeClientCommand
                && $command->userId->equals($userId)
                && $command->clientPublicId->equals($publicId)))
            ->willReturnCallback(static fn (object $command): Envelope => self::handled($command, null));

        $data = $this->decode($this->controller->revoke($publicId->toString()), 200);

        self::assertSame('success.client_revoked', $data['data']['message']);
    }

    public function testRevokeMapsUnknownOrForeignClientToNotFound(): void
    {
        $this->authenticate();
        $this->bus->expects(self::once())->method('dispatch')->willReturnCallback(static function (object $command): never {
            throw new HandlerFailedException(new Envelope($command), [ClientNotFoundException::forOwner()]);
        });

        self::assertSame(404, $this->controller->revoke((new PublicId())->toString())->getStatusCode());
    }

    public function testRevokeDoesNotMaskUnexpectedFailures(): void
    {
        $this->authenticate();
        $failure = new HandlerFailedException(new Envelope(new \stdClass()), [new \RuntimeException('Database unavailable')]);
        $this->bus->expects(self::once())->method('dispatch')->willThrowException($failure);

        $this->expectExceptionObject($failure);

        $this->controller->revoke((new PublicId())->toString());
    }

    public function testRevokeRejectsMalformedPublicIdWithoutDispatching(): void
    {
        $this->authenticate();
        $this->bus->expects(self::never())->method('dispatch');

        $data = $this->decode($this->controller->revoke('not a public id'), 400);

        self::assertSame('errors.invalid_public_id', $data['error']['message']);
    }

    private function authenticate(): Uuid
    {
        $userId = Uuid::generate();
        $this->security->method('getUser')->willReturn(new SecurityUser($userId->toString(), 'owner@baander.app', 'unused'));

        return $userId;
    }

    private static function handled(object $message, mixed $result): Envelope
    {
        return (new Envelope($message))->with(new HandledStamp($result, 'handler'));
    }

    /** @return array<string, mixed> */
    private function decode(JsonResponse $response, int $status): array
    {
        $content = $response->getContent();
        self::assertIsString($content);
        self::assertSame($status, $response->getStatusCode(), $content);

        return json_decode($content, true, 16, JSON_THROW_ON_ERROR);
    }
}
