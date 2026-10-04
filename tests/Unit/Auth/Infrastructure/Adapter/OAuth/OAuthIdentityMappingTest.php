<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Adapter\OAuth;

use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\OAuth\DeviceCode;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\OAuth\AuthCodeRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\ClientRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\DeviceCodeRepositoryInterface;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Auth\Infrastructure\Adapter\OAuth\AuthCodeRepository;
use App\Auth\Infrastructure\Adapter\OAuth\DeviceCodeRepository;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\ClientEntity;
use App\Shared\Domain\Model\Email;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class OAuthIdentityMappingTest extends TestCase
{
    public function testAuthCodeIssuanceUsesUserSelectedByLeagueGrant(): void
    {
        $user = User::register(new Email('grant@baander.app'), 'hashed', 'Grant user');
        $client = Client::create('Grant client', ['https://baander.app/oauth/callback']);
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->expects($this->once())->method('findByUuid')->with($this->equalTo($user->getId()))->willReturn($user);
        $clients = $this->createMock(ClientRepositoryInterface::class);
        $clients->expects($this->once())->method('findClientByUuid')->with($this->equalTo($client->getId()))->willReturn($client);
        $codes = $this->createMock(AuthCodeRepositoryInterface::class);
        $codes->method('findByCodeId')->willReturn(null);
        $codes->expects($this->once())->method('save')->with($this->callback(
            fn (\App\Auth\Domain\Model\OAuth\AuthCode $code): bool => $code->getUser()->getId()->equals($user->getId())
                && $code->getClient()->getId()->equals($client->getId()),
        ));
        $adapter = new AuthCodeRepository($codes, $clients, $users);
        $entity = $adapter->getNewAuthCode();
        $entity->setUserIdentifier($user->getId()->toString());
        $entity->setClient(new ClientEntity($client->getPublicId(), $client->getName(), '[]', id: $client->getId()));
        $entity->setExpiryDateTime(new \DateTimeImmutable('+10 minutes'));
        $this->assertSame($user->getId()->toString(), $entity->getUserIdentifier());
        $adapter->persistNewAuthCode($entity);
    }

    public function testDeviceCodeReconstructionPreservesAssociationIdentitiesAndTotp(): void
    {
        $user = User::register(new Email('device@baander.app'), 'hashed', 'Device user');
        $user->setTotpSecret('secret');
        $client = Client::create('Device client', ['https://baander.app/oauth/callback']);
        $domain = DeviceCode::create($client, 'ABCD-EFGH', '/device/verify');
        $domain->approve($user);
        $codes = $this->createStub(DeviceCodeRepositoryInterface::class);
        $codes->method('findByDeviceCode')->willReturn($domain);
        $adapter = new DeviceCodeRepository($codes, $this->createStub(ClientRepositoryInterface::class), $this->createStub(UserRepositoryInterface::class), new JsonEncoder());
        $entity = $adapter->getDeviceCodeEntityByDeviceCode($domain->getDeviceCode()->toString());
        $this->assertInstanceOf(\App\Auth\Infrastructure\Doctrine\Entity\OAuth\DeviceCodeEntity::class, $entity);
        $this->assertSame($user->getId()->toString(), $entity->getUserIdentifier());
        $this->assertSame($client->getId()->toString(), $entity->getClient()->getId()->toString());
        $this->assertSame('secret', $entity->getUser()?->getTotpSecret());
    }
}
