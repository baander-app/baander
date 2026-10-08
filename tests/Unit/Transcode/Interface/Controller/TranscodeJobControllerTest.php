<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Interface\Controller;

use App\Auth\Infrastructure\Security\SecurityUser;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\Port\TranscodeJobPortInterface;
use App\Transcode\Application\Query\TranscodeJobQueryPort;
use App\Transcode\Interface\Controller\TranscodeJobController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadataFactory;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Controller\UserValueResolver;

final class TranscodeJobControllerTest extends TestCase
{
    public function testListQueriesTheIdentityResolvedFromTheAuthenticatedPrincipal(): void
    {
        $id = Uuid::generate();
        $user = new SecurityUser($id->toString(), 'viewer@baander.app', '');
        $storage = new TokenStorage();
        $storage->setToken(new UsernamePasswordToken($user, 'api', $user->getRoles()));
        $controller = $this->controller();
        $argument = $this->userArgument($controller);
        $query = $this->createMock(TranscodeJobQueryPort::class);
        $query->expects($this->once())->method('findByUser')
            ->with($this->callback(static fn (Uuid $value): bool => $value->equals($id)))
            ->willReturn([]);

        $resolved = (new UserValueResolver($storage))->resolve(Request::create('/api/transcode/jobs/list'), $argument);
        self::assertSame([$user], $resolved);
        $response = $controller->listJobs($query, $user);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['data' => []], json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testListRequiresAnAuthenticatedPrincipal(): void
    {
        $controller = $this->controller();
        $argument = $this->userArgument($controller);
        $this->expectException(AccessDeniedException::class);

        (new UserValueResolver(new TokenStorage()))->resolve(Request::create('/api/transcode/jobs/list'), $argument);
    }

    private function controller(): TranscodeJobController
    {
        return new TranscodeJobController(
            $this->createStub(MessageBusInterface::class),
            $this->createStub(TranscodeJobPortInterface::class),
        );
    }

    private function userArgument(TranscodeJobController $controller): ArgumentMetadata
    {
        foreach ((new ArgumentMetadataFactory())->createArgumentMetadata([$controller, 'listJobs']) as $argument) {
            if ($argument->getName() === 'user') {
                return $argument;
            }
        }

        self::fail('The action must request the authenticated principal explicitly.');
    }
}
