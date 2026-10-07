<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Repository\OAuth;

use App\Auth\Domain\Model\OAuth\AuthCode;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\OAuth\ValueObject\ClientSecret;
use App\Auth\Domain\Model\OAuth\ValueObject\Scope;
use App\Auth\Domain\Model\User;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\AuthCodeEntity;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\ClientEntity;
use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Auth\Infrastructure\Repository\OAuth\AuthCodeRepository;
use App\Shared\Domain\Model\Email;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

/**
 * Validation test: repositories that participate in application-level
 * transactions must not call EntityManager::flush(). Flushing inside a
 * repository save() forces an immediate commit of all pending changes and
 * breaks the atomicity promised by the handler's connection->transactional()
 * boundary.
 */
final class AuthCodeRepositoryFlushTest extends TestCase
{
    private EntityManagerInterface&MockObject $entityManager;
    private AuthCodeRepository $repository;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->repository = new AuthCodeRepository(
            $this->entityManager,
            new JsonEncoder(),
        );
    }

    public function testSaveDoesNotFlushInsideTransaction(): void
    {
        $user = User::register(new Email('user@baander.app'), 'hashed-pw', 'Test User');
        $client = Client::create(
            name: 'Test App',
            redirectUris: ['http://localhost'],
            secret: ClientSecret::fromString('test-secret'),
            confidential: true,
            firstParty: true,
        );
        $authCode = AuthCode::create($user, $client, 'http://localhost', str_repeat('A', 43), 'S256', [new Scope('profile')]);

        $clientEntity = $this->createStub(ClientEntity::class);
        $userEntity = $this->createStub(UserEntity::class);

        $clientRepo = $this->createStub(EntityRepository::class);
        $clientRepo->method('find')->willReturn($clientEntity);

        $userRepo = $this->createStub(EntityRepository::class);
        $userRepo->method('find')->willReturn($userEntity);

        $authCodeRepo = $this->createStub(EntityRepository::class);
        $authCodeRepo->method('find')->willReturn(null);

        $this->entityManager
            ->method('getRepository')
            ->willReturnMap([
                [AuthCodeEntity::class, $authCodeRepo],
                [ClientEntity::class, $clientRepo],
                [UserEntity::class, $userRepo],
            ]);

        $this->entityManager->expects($this->once())->method('persist');
        // The repository must NOT flush; the application service (handler) owns
        // the transaction boundary and should flush once after all changes.
        $this->entityManager->expects($this->never())->method('flush');

        $this->repository->save($authCode, false);
    }
}
