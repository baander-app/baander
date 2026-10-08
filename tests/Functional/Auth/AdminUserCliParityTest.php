<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use App\Auth\Domain\Event\UserCreatedByOperator;
use App\Auth\Domain\Model\User;
use App\Auth\Interface\Console\CreateUserCommand;
use App\Shared\Domain\Model\Email;
use App\Tests\Functional\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Every user action of the admin page through the admin API and through its app:user:*
 * command: both reach the same use case, so both leave the same stored state.
 */
final class AdminUserCliParityTest extends TestCase
{
    private const string PATH = '/api/admin/users';

    public function testDisableAndEnableLeaveTheSameStateAndSucceedWhenRepeated(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $viaApi = $this->createTestUser();
        $viaCli = $this->createTestUser();

        foreach ([1, 2] as $attempt) {
            $data = $this->assertJsonResponse($this->authenticatedRequest('POST', $this->userPath($viaApi) . '/disable', $superAdmin), 200, 'data')['data'];
            self::assertTrue($data['disabled'], 'API attempt ' . $attempt);
            $disable = $this->command('app:user:disable');
            self::assertSame(Command::SUCCESS, $disable->execute(['identifier' => $viaCli->getEmail()]), 'CLI attempt ' . $attempt . ': ' . $disable->getDisplay());
        }
        self::assertSame($this->state($viaApi), $this->state($viaCli));
        self::assertTrue($this->stored($viaCli)->isDisabled());

        foreach ([1, 2] as $attempt) {
            $data = $this->assertJsonResponse($this->authenticatedRequest('POST', $this->userPath($viaApi) . '/enable', $superAdmin), 200, 'data')['data'];
            self::assertFalse($data['disabled'], 'API attempt ' . $attempt);
            $enable = $this->command('app:user:enable');
            self::assertSame(Command::SUCCESS, $enable->execute(['identifier' => $viaCli->getId()->toString()]), 'CLI attempt ' . $attempt);
        }
        self::assertSame($this->state($viaApi), $this->state($viaCli));
        self::assertFalse($this->stored($viaCli)->isDisabled());
    }

    public function testCreatingASuperAdminSeedsPreferencesAndAnnouncesTheUser(): void
    {
        $announced = [];
        $dispatcher = static::getContainer()->get(EventDispatcherInterface::class);
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(UserCreatedByOperator::class, static function (UserCreatedByOperator $event) use (&$announced): void {
            $announced[] = $event->getEmail()->toString();
        });

        $stdin = fopen('php://memory', 'r+');
        self::assertIsResource($stdin);
        fwrite($stdin, "console-password-123\n");
        rewind($stdin);
        $bus = static::getContainer()->get(MessageBusInterface::class);
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $create = new CommandTester(new CreateUserCommand($bus, $stdin));

        self::assertSame(Command::SUCCESS, $create->execute([
            'email' => 'root@baander.app',
            'name' => 'Root',
            '--password' => true,
            '--role' => ['super-admin'],
        ], ['interactive' => false]), $create->getDisplay());

        $this->entityManager->clear();
        $user = $this->userRepository->findByEmail(new Email('root@baander.app'));
        self::assertNotNull($user);
        self::assertSame(['ROLE_SUPER_ADMIN'], $user->getRoles());
        self::assertTrue($user->hasRole('ROLE_ADMIN'));
        self::assertTrue($user->isEmailVerified());
        self::assertSame(['root@baander.app'], $announced);
        self::assertSame(12, (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM notification_preferences WHERE user_id = ?',
            [$user->getId()->toString()],
        ));
    }

    public function testRolesAreReplacedAlikeOnBothPaths(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $viaApi = $this->createAdminUser();
        $viaCli = $this->createAdminUser();

        $this->assertJsonResponse($this->authenticatedRequest('POST', $this->userPath($viaApi) . '/roles', $superAdmin, ['roles' => ['ROLE_USER', 'ROLE_SUPER_ADMIN']]), 200, 'data');
        $roles = $this->command('app:user:roles');
        self::assertSame(Command::SUCCESS, $roles->execute(['identifier' => $viaCli->getEmail(), 'roles' => ['ROLE_USER', 'ROLE_SUPER_ADMIN']]), $roles->getDisplay());

        self::assertSame(['ROLE_USER', 'ROLE_SUPER_ADMIN'], $this->stored($viaApi)->getRoles());
        self::assertSame($this->stored($viaApi)->getRoles(), $this->stored($viaCli)->getRoles(), 'The roles are replaced, not added to.');

        $invalid = $this->command('app:user:roles');
        self::assertSame(Command::INVALID, $invalid->execute(['identifier' => $viaCli->getEmail(), 'roles' => ['ROLE_OWNER']]));
        self::assertSame(['ROLE_USER', 'ROLE_SUPER_ADMIN'], $this->stored($viaCli)->getRoles());
    }

    public function testDeletingNeedsConfirmationOnTheCliAndDeletesAlikeOnBothPaths(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $viaApi = $this->createTestUser();
        $viaCli = $this->createTestUser();

        $unconfirmed = $this->command('app:user:delete');
        self::assertSame(Command::INVALID, $unconfirmed->execute(['identifier' => $viaCli->getEmail()], ['interactive' => false]));
        self::assertNotNull($this->userRepository->findByUuid($viaCli->getId()), 'Nothing is deleted without a terminal or --force.');

        self::assertSame(204, $this->authenticatedRequest('DELETE', $this->userPath($viaApi), $superAdmin)->getStatusCode());
        $delete = $this->command('app:user:delete');
        self::assertSame(Command::SUCCESS, $delete->execute(['identifier' => $viaCli->getEmail(), '--force' => true], ['interactive' => false]), $delete->getDisplay());

        $this->entityManager->clear();
        self::assertNull($this->userRepository->findByUuid($viaApi->getId()));
        self::assertNull($this->userRepository->findByUuid($viaCli->getId()));
    }

    public function testRenamingStoresTheSameNameAndRejectsTheSameNames(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $viaApi = $this->createTestUser();
        $viaCli = $this->createTestUser();

        $this->assertJsonResponse($this->authenticatedRequest('PATCH', $this->userPath($viaApi), $superAdmin, ['name' => 'Ånne Renamed']), 200, 'data');
        $rename = $this->command('app:user:rename');
        self::assertSame(Command::SUCCESS, $rename->execute(['identifier' => $viaCli->getEmail(), 'name' => 'Ånne Renamed']), $rename->getDisplay());
        self::assertSame('Ånne Renamed', $this->stored($viaApi)->getName());
        self::assertSame($this->stored($viaApi)->getName(), $this->stored($viaCli)->getName());

        foreach (['   ', str_repeat('n', 256)] as $name) {
            $error = $this->assertJsonResponse($this->authenticatedRequest('PATCH', $this->userPath($viaApi), $superAdmin, ['name' => $name]), 422, 'error');
            $apiMessages = $error['error']['details']['name'] ?? null;
            self::assertIsArray($apiMessages, (string) json_encode($error));
            self::assertCount(1, $apiMessages);

            $rejected = $this->command('app:user:rename');
            self::assertSame(Command::INVALID, $rejected->execute(['identifier' => $viaCli->getEmail(), 'name' => $name]));
            self::assertStringContainsString($apiMessages[0], preg_replace('/\s+/', ' ', $rejected->getDisplay()) ?? '');
        }
        self::assertSame('Ånne Renamed', $this->stored($viaApi)->getName());
        self::assertSame('Ånne Renamed', $this->stored($viaCli)->getName());
    }

    public function testTheFilteredListMatchesTheApi(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $disabledAdmins = [$this->createAdminUser(), $this->createAdminUser()];
        $this->createAdminUser();
        foreach ($disabledAdmins as $admin) {
            self::assertSame(Command::SUCCESS, $this->command('app:user:disable')->execute(['identifier' => $admin->getEmail()]));
        }

        $api = $this->assertJsonResponse($this->authenticatedRequest('GET', self::PATH . '?role=ROLE_ADMIN&disabled=true', $superAdmin), 200, 'data');
        $listed = array_column($api['data'], 'email');
        foreach ($disabledAdmins as $admin) {
            self::assertContains($admin->getEmail(), $listed);
        }
        self::assertNotContains($superAdmin->getEmail(), $listed);

        $json = $this->command('app:user:list');
        self::assertSame(Command::SUCCESS, $json->execute(['--role' => 'ROLE_ADMIN', '--disabled' => null, '--json' => true]));
        self::assertSame($api['data'], json_decode($json->getDisplay(), true, 512, JSON_THROW_ON_ERROR));

        $table = $this->command('app:user:list');
        self::assertSame(Command::SUCCESS, $table->execute(['--role' => 'ROLE_ADMIN', '--disabled' => null]));
        foreach ($disabledAdmins as $admin) {
            self::assertStringContainsString($admin->getEmail(), $table->getDisplay());
        }
        self::assertStringNotContainsString($superAdmin->getEmail(), $table->getDisplay());
    }

    public function testAnUnknownUserFailsOnTheCliAndIsNotFoundByTheApi(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $unknown = 'nobody@baander.app';

        foreach ([
            ['app:user:disable', ['identifier' => $unknown]],
            ['app:user:enable', ['identifier' => $unknown]],
            ['app:user:rename', ['identifier' => $unknown, 'name' => 'Nobody']],
            ['app:user:roles', ['identifier' => $unknown, 'roles' => ['ROLE_USER']]],
            ['app:user:delete', ['identifier' => $unknown, '--force' => true]],
        ] as [$name, $arguments]) {
            $command = $this->command($name);
            self::assertSame(Command::FAILURE, $command->execute($arguments, ['interactive' => false]), $name);
            self::assertStringContainsString('User "nobody@baander.app" not found.', preg_replace('/\s+/', ' ', $command->getDisplay()) ?? '', $name);
        }

        foreach ([
            ['POST', self::PATH . '/' . $unknown . '/disable', []],
            ['POST', self::PATH . '/' . $unknown . '/enable', []],
            ['PATCH', self::PATH . '/' . $unknown, ['name' => 'Nobody']],
            ['POST', self::PATH . '/' . $unknown . '/roles', ['roles' => ['ROLE_USER']]],
            ['DELETE', self::PATH . '/' . $unknown, []],
            ['POST', self::PATH . '/0190f5c4-0000-7000-8000-000000000000/disable', []],
        ] as [$method, $path, $body]) {
            self::assertSame(404, $this->authenticatedRequest($method, $path, $superAdmin, $body)->getStatusCode(), $method . ' ' . $path);
        }
    }

    private function userPath(User $user): string
    {
        return self::PATH . '/' . $user->getId()->toString();
    }

    private function stored(User $user): User
    {
        $this->entityManager->clear();
        $stored = $this->userRepository->findByUuid($user->getId());
        self::assertNotNull($stored);

        return $stored;
    }

    /** @return array{disabled: bool, roles: list<string>, active_access_tokens: int} */
    private function state(User $user): array
    {
        $stored = $this->stored($user);

        return [
            'disabled' => $stored->isDisabled(),
            'roles' => array_values($stored->getRoles()),
            'active_access_tokens' => (int) $this->entityManager->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM oauth_access_tokens WHERE user_id = ? AND revoked = FALSE',
                [$user->getId()->toString()],
            ),
        ];
    }

    private function command(string $name): CommandTester
    {
        return new CommandTester((new Application($this->client->getKernel()))->find($name));
    }
}
