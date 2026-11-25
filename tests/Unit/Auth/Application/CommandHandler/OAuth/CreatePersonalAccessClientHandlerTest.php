<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Application\CommandHandler\OAuth;

use App\Auth\Application\Command\OAuth\CreatePersonalAccessClientCommand;
use App\Auth\Application\CommandHandler\OAuth\CreatePersonalAccessClientHandler;
use App\Auth\Application\Exception\PersonalAccessClientLimitReachedException;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Repository\OAuth\ClientRepositoryInterface;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;

final class CreatePersonalAccessClientHandlerTest extends TestCase
{
    /** @var list<string> */
    private array $steps = [];

    public function testCreatesAndPersistsAPublicPersonalAccessClientForTheOwner(): void
    {
        $owner = Uuid::generate();
        $saved = null;
        $repository = $this->repository(19);
        $repository->expects(self::once())->method('saveClient')
            ->willReturnCallback(function (Client $client) use (&$saved): void {
                $saved = $client;
                $this->steps[] = 'save';
            });

        $client = $this->handler($repository)(new CreatePersonalAccessClientCommand($owner, 'Library player'));

        self::assertSame($saved, $client);
        self::assertSame(['begin', 'lock', 'count', 'save', 'commit'], $this->steps, 'The owner\'s clients are counted under the lock.');
        self::assertSame('Library player', $client->getName());
        self::assertTrue($client->isOwnedBy($owner));
        self::assertTrue($client->isPersonalAccessClient());
        self::assertFalse($client->isFirstParty(), 'A personal access client is not first-party, so its requests need consent.');
        self::assertFalse($client->isConfidential());
        self::assertNull($client->getSecretHash());
        self::assertFalse($client->isRevoked());
    }

    public function testAnOwnerHoldingTwentyClientsIsRefused(): void
    {
        $repository = $this->repository(CreatePersonalAccessClientHandler::CLIENTS_PER_USER);
        $repository->expects(self::never())->method('saveClient');

        try {
            $this->handler($repository)(new CreatePersonalAccessClientCommand(Uuid::generate(), 'One too many'));
            self::fail('The 21st client must be refused.');
        } catch (PersonalAccessClientLimitReachedException $exception) {
            self::assertSame(['reason' => 'personal_access_client_limit', 'limit' => 20], $exception->details);
        }
        self::assertSame(['begin', 'lock', 'count', 'rollback'], $this->steps);
    }

    public function testBlankNameIsRejectedBeforePersistence(): void
    {
        $repository = $this->repository(0);
        $repository->expects(self::never())->method('saveClient');

        $this->expectException(\InvalidArgumentException::class);

        $this->handler($repository)(new CreatePersonalAccessClientCommand(Uuid::generate(), '   '));
    }

    /** @return ClientRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject */
    private function repository(int $heldClients): ClientRepositoryInterface
    {
        $repository = $this->createMock(ClientRepositoryInterface::class);
        $repository->method('lockPersonalAccessClientsOf')->willReturnCallback(function (): void {
            $this->steps[] = 'lock';
        });
        $repository->method('countPersonalAccessClientsOf')->willReturnCallback(function () use ($heldClients): int {
            $this->steps[] = 'count';

            return $heldClients;
        });

        return $repository;
    }

    private function handler(ClientRepositoryInterface $repository): CreatePersonalAccessClientHandler
    {
        $transaction = $this->createStub(TransactionPortInterface::class);
        $transaction->method('run')->willReturnCallback(function (callable $operation): mixed {
            $this->steps[] = 'begin';
            try {
                $result = $operation();
            } catch (\Throwable $failure) {
                $this->steps[] = 'rollback';

                throw $failure;
            }
            $this->steps[] = 'commit';

            return $result;
        });

        return new CreatePersonalAccessClientHandler($repository, $transaction);
    }
}
