<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Application\CommandHandler;

use App\Auth\Application\Command\Passkey\DeletePasskeyCommand;
use App\Auth\Application\CommandHandler\Passkey\DeletePasskeyHandler;
use App\Auth\Application\Exception\StepUpFailedException;
use App\Auth\Domain\Event\Passkey\PasskeyDeleted;
use App\Auth\Domain\Model\Passkey\Passkey;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\Passkey\PasskeyRepositoryInterface;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Removing one's own passkey needs the current password, plus a current code when TOTP is on.
 * It records PasskeyDeleted, keeps the requesting session, ends the others and closes their
 * connections once it has committed.
 */
final class DeletePasskeyHandlerTest extends TestCase
{
    use RecordsCredentialChanges;

    private User $user;
    private Passkey $laptop;
    private Passkey $phone;
    /** @var list<string> the passkeys removed and the events recorded, each marked when it ran inside the transaction */
    private array $writes = [];

    protected function setUp(): void
    {
        $this->user = User::register(new Email('test@baander.app'), 'hashed', 'Alice');
        $this->laptop = Passkey::create(Uuid::v7(), 'Laptop', 'laptop-credential', [], 0);
        $this->phone = Passkey::create(Uuid::v7(), 'Phone', 'phone-credential', [], 0);
    }

    public function testStepUpRemovesThePasskeyRecordsItAndEndsEveryOtherSession(): void
    {
        $this->user->enableTotp('sealed-secret');
        $session = $this->signedInSession($this->user);

        $kept = $this->handler()(new DeletePasskeyCommand(
            $this->user->getId()->toString(),
            $this->laptop->getPublicId()->toString(),
            'current-password',
            '123456',
            $session,
        ));

        self::assertSame(['phone-credential'], $kept);
        self::assertSame([
            'remove Laptop in transaction',
            'event ' . $this->laptop->getId()->toString() . ' in transaction',
        ], $this->writes);
        self::assertSame([
            'end sessions keeping ' . $session,
            'commit',
            'close other-session-token',
        ], $this->credentialChanges);
    }

    public function testWithTotpOnThePasswordAloneRemovesNothing(): void
    {
        $this->user->enableTotp('sealed-secret');

        try {
            $this->handler()(new DeletePasskeyCommand($this->user->getId()->toString(), $this->laptop->getPublicId()->toString(), 'current-password'));
            self::fail('A missing code must be refused.');
        } catch (StepUpFailedException) {
        }

        self::assertSame([], $this->writes);
        self::assertSame([], $this->credentialChanges);
    }

    public function testAPasskeyTheUserDoesNotHaveIsNotFoundBeforeAnyStepUpAttempt(): void
    {
        try {
            $this->handler()(new DeletePasskeyCommand($this->user->getId()->toString(), (new PublicId())->toString(), 'current-password'));
            self::fail('An unknown passkey must be reported.');
        } catch (NotFoundException) {
        }

        self::assertSame(0, $this->stepUpAttempts);
        self::assertSame([], $this->writes);
    }

    private function handler(): DeletePasskeyHandler
    {
        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('findByUuid')->willReturn($this->user);
        $passkeys = $this->createStub(PasskeyRepositoryInterface::class);
        $passkeys->method('forUser')->willReturn([$this->laptop, $this->phone]);
        $passkeys->method('remove')->willReturnCallback(function (Passkey $passkey): void {
            $this->writes[] = 'remove ' . $passkey->getName() . ($this->inTransaction ? ' in transaction' : '');
        });
        $events = $this->createStub(EventDispatcherInterface::class);
        $events->method('dispatch')->willReturnCallback(function (object $event): object {
            self::assertInstanceOf(PasskeyDeleted::class, $event);
            self::assertTrue($this->user->getId()->equals($event->getUserId()));
            $this->writes[] = 'event ' . $event->getPasskeyId()->toString() . ($this->inTransaction ? ' in transaction' : '');

            return $event;
        });

        return new DeletePasskeyHandler(
            $users,
            $passkeys,
            $this->stepUp(),
            $this->credentialChangeRevoker(),
            $this->transaction(),
            $events,
        );
    }
}
