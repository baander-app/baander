<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Application\CommandHandler;

use App\Auth\Application\Command\OAuth\ApproveDeviceCodeCommand;
use App\Auth\Application\Command\OAuth\DenyDeviceCodeCommand;
use App\Auth\Application\CommandHandler\OAuth\ApproveDeviceCodeHandler;
use App\Auth\Application\CommandHandler\OAuth\DenyDeviceCodeHandler;
use App\Auth\Application\Exception\DeviceUserCodeException;
use App\Auth\Application\Query\OAuth\GetDeviceAuthorizationQuery;
use App\Auth\Application\QueryHandler\OAuth\GetDeviceAuthorizationHandler;
use App\Auth\Application\Service\PendingDeviceCodeFinder;
use App\Auth\Domain\Event\OAuth\DeviceCodeApproved;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\OAuth\DeviceCode;
use App\Auth\Domain\Model\OAuth\ValueObject\Scope;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\OAuth\DeviceCodeRepositoryInterface;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\Uuid;
use DateInterval;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/** Approving, denying and looking up device authorization requests by user code (RFC 8628 section 3.3). */
final class ApproveDeviceCodeHandlerTest extends TestCase
{
    private const string USER_CODE = 'BCDF-GHJK';

    /** @var array<string, DeviceCode> */
    private array $codes = [];
    /** @var list<DeviceCode> */
    private array $saved = [];
    /** @var list<object> */
    private array $events = [];
    private ?User $user;

    protected function setUp(): void
    {
        $this->user = User::register(new Email('viewer@baander.app'), 'hashed', 'Alice');
    }

    public function testApprovalBindsTheUserAndNotifies(): void
    {
        $code = $this->pending();

        ($this->approveHandler())(new ApproveDeviceCodeCommand('bcdf ghjk', $this->user()->getId()));

        self::assertTrue($code->isApproved());
        self::assertSame($this->user, $code->getUser());
        self::assertSame([$code], $this->saved);
        self::assertCount(1, $this->events);
        self::assertInstanceOf(DeviceCodeApproved::class, $this->events[0]);
        self::assertSame($code->getId()->toString(), $this->events[0]->getDeviceCodeId());
    }

    public function testDenialMarksTheRequestDenied(): void
    {
        $code = $this->pending();

        ($this->denyHandler())(new DenyDeviceCodeCommand(self::USER_CODE, $this->user()->getId()));

        self::assertTrue($code->isDenied());
        self::assertSame([$code], $this->saved);
    }

    public function testLookupShowsTheClientAndScopes(): void
    {
        $this->pending();

        $pending = ($this->lookupHandler())(new GetDeviceAuthorizationQuery('BCDFGHJK'));

        self::assertSame(self::USER_CODE, $pending->userCode);
        self::assertSame('Living room TV', $pending->clientName);
        self::assertSame(['library'], $pending->scopes);
    }

    /** Each invalid input is checked against approval, denial and lookup. */
    public function testUnknownMalformedAndExpiredCodesAreInvalid(): void
    {
        $this->pending(new DateInterval('PT0S'));
        usleep(1000);

        foreach (['ZZZZ-ZZZZ', 'not a code', self::USER_CODE] as $input) {
            $this->assertReason(DeviceUserCodeException::INVALID, fn () => ($this->approveHandler())(new ApproveDeviceCodeCommand($input, $this->user()->getId())));
            $this->assertReason(DeviceUserCodeException::INVALID, fn () => ($this->denyHandler())(new DenyDeviceCodeCommand($input, $this->user()->getId())));
            $this->assertReason(DeviceUserCodeException::INVALID, fn () => ($this->lookupHandler())(new GetDeviceAuthorizationQuery($input)));
        }
        self::assertSame([], $this->saved);
    }

    public function testADecidedRequestCannotBeDecidedAgain(): void
    {
        $code = $this->pending();
        $code->deny();

        $this->assertReason(DeviceUserCodeException::ALREADY_PROCESSED, fn () => ($this->approveHandler())(new ApproveDeviceCodeCommand(self::USER_CODE, $this->user()->getId())));
        $this->assertReason(DeviceUserCodeException::ALREADY_PROCESSED, fn () => ($this->lookupHandler())(new GetDeviceAuthorizationQuery(self::USER_CODE)));
        self::assertFalse($code->isApproved());
    }

    public function testApprovalByAnUnknownUserFails(): void
    {
        $code = $this->pending();
        $this->user = null;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('User not found');

        try {
            ($this->approveHandler())(new ApproveDeviceCodeCommand(self::USER_CODE, Uuid::v4()));
        } finally {
            self::assertTrue($code->isPending());
        }
    }

    private function pending(?DateInterval $ttl = null): DeviceCode
    {
        $client = Client::create('Living room TV', [], deviceClient: true);
        $code = DeviceCode::create($client, self::USER_CODE, 'https://baander.app/device', scopes: [new Scope('library')], ttl: $ttl ?? new DateInterval('PT15M'));
        $this->codes[self::USER_CODE] = $code;

        return $code;
    }

    private function repository(): DeviceCodeRepositoryInterface
    {
        $repository = $this->createStub(DeviceCodeRepositoryInterface::class);
        $repository->method('findByUserCode')->willReturnCallback(fn (string $userCode): ?DeviceCode => $this->codes[$userCode] ?? null);
        $repository->method('save')->willReturnCallback(function (DeviceCode $code): void {
            $this->saved[] = $code;
        });

        return $repository;
    }

    private function approveHandler(): ApproveDeviceCodeHandler
    {
        $repository = $this->repository();
        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('findByUuid')->willReturnCallback(fn (): ?User => $this->user);
        $connection = $this->createStub(Connection::class);
        $connection->method('transactional')->willReturnCallback(static fn (callable $callback) => $callback());
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);
        $dispatcher = $this->createStub(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnCallback(function (object $event): object {
            $this->events[] = $event;

            return $event;
        });

        return new ApproveDeviceCodeHandler($repository, new PendingDeviceCodeFinder($repository), $users, $entityManager, $dispatcher);
    }

    private function denyHandler(): DenyDeviceCodeHandler
    {
        $repository = $this->repository();

        return new DenyDeviceCodeHandler(new PendingDeviceCodeFinder($repository), $repository);
    }

    private function lookupHandler(): GetDeviceAuthorizationHandler
    {
        return new GetDeviceAuthorizationHandler(new PendingDeviceCodeFinder($this->repository()));
    }

    private function user(): User
    {
        self::assertNotNull($this->user);

        return $this->user;
    }

    private function assertReason(string $reason, callable $action): void
    {
        try {
            $action();
            self::fail('Expected a DeviceUserCodeException.');
        } catch (DeviceUserCodeException $exception) {
            self::assertSame($reason, $exception->reason);
        }
    }
}
