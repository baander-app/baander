<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Adapter\OAuth;

use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\ClientEntity;
use App\Auth\Infrastructure\Repository\OAuth\ClientRepository as DoctrineClients;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class ClientIdentityTest extends TestCase
{
    public function testNewClientPersistenceUsesTheDomainIdentifierAndOwner(): void
    {
        $owner = Uuid::generate();
        $client = Client::createPersonalAccess('Player', $owner);
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::once())->method('find')->with($client->getId())->willReturn(null);
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects(self::once())->method('getRepository')->with(ClientEntity::class)->willReturn($repository);
        $manager->expects(self::once())->method('persist')->with(self::callback(static function (object $entity) use ($client, $owner): bool {
            self::assertInstanceOf(ClientEntity::class, $entity);
            self::assertTrue($client->getId()->equals($entity->getId()));
            self::assertNotNull($entity->getUserId());
            self::assertTrue($owner->equals($entity->getUserId()));
            return true;
        }));
        $manager->expects(self::once())->method('flush');
        new DoctrineClients($manager, new JsonEncoder())->saveClient($client);
    }
}
