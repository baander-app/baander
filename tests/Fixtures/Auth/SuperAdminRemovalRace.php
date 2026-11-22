<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Auth;

use App\Auth\Application\Command\User\DeleteUserCommand;
use App\Auth\Application\Command\User\DisableUserCommand;
use App\Auth\Application\Command\User\SetUserRolesCommand;
use App\Auth\Application\CommandHandler\User\DeleteUserHandler;
use App\Auth\Application\CommandHandler\User\DisableUserHandler;
use App\Auth\Application\CommandHandler\User\SetUserRolesHandler;
use App\Auth\Application\Service\SuperAdminContinuity;
use App\Auth\Application\Service\UserLookup;
use App\Auth\Infrastructure\Doctrine\UserRepository;
use App\Auth\Infrastructure\Repository\EmailVerificationTokenRepository;
use App\Auth\Infrastructure\Repository\OAuth\AccessTokenRepository;
use App\Auth\Infrastructure\Repository\OAuth\RefreshTokenRepository;
use App\Auth\Infrastructure\Repository\PasswordResetTokenRepository;
use App\Shared\Application\Port\LiveConnectionsPortInterface;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\EntityManager;
use Psr\Log\NullLogger;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

/**
 * The production demotion, disable and deletion over one disposable schema, shared by
 * SuperAdminRemovalRaceTest and the child process it runs (super-admin-removal.php) as a second
 * PostgreSQL session. The connection and mapping are the OAuth revocation race's: the Auth
 * entities in a schema of their own.
 */
final class SuperAdminRemovalRace
{
    /** The roles a demotion leaves: an admin without ROLE_SUPER_ADMIN. */
    public const array DEMOTED_ROLES = ['ROLE_USER', 'ROLE_ADMIN'];

    public static function users(EntityManager $manager): UserRepository
    {
        $connection = $manager->getConnection();

        return new UserRepository($manager, new PasswordResetTokenRepository($connection), new EmailVerificationTokenRepository($connection));
    }

    /**
     * Applies the removal to the user through its production handler.
     *
     * @param (callable(): void)|null $beforeCommit Runs inside the removal's transaction, after its writes
     */
    public static function remove(EntityManager $manager, SuperAdminRemoval $removal, string $email, ?callable $beforeCommit = null): void
    {
        $users = self::users($manager);
        $lookup = new UserLookup($users);
        $transaction = self::transaction($manager, $beforeCommit);
        $continuity = new SuperAdminContinuity($users);

        match ($removal) {
            SuperAdminRemoval::Demotion => new SetUserRolesHandler($lookup, $users, $transaction, $continuity)(
                new SetUserRolesCommand($email, self::DEMOTED_ROLES),
            ),
            SuperAdminRemoval::Disable => new DisableUserHandler(
                $lookup,
                $users,
                new AccessTokenRepository($manager, new JsonEncoder()),
                new RefreshTokenRepository($manager, new JsonEncoder()),
                $transaction,
                $continuity,
                self::noLiveConnections(),
                new NullLogger(),
            )(new DisableUserCommand($email)),
            SuperAdminRemoval::Deletion => new DeleteUserHandler($lookup, $users, $transaction, $continuity, self::noLiveConnections(), new NullLogger())(
                new DeleteUserCommand($email),
            ),
        };
    }

    /**
     * Starts the child session, which applies the removal and prints the user as stored
     * afterwards, or the reason the removal was refused.
     *
     * @return array{process: resource, output: resource, application: string}
     */
    public static function start(string $schema, SuperAdminRemoval $removal, string $email): array
    {
        $application = 'super_admin_race_' . (random_bytes(6) |> bin2hex(...));
        $output = tmpfile();
        if ($output === false) {
            throw new \RuntimeException('Cannot create the removal output.');
        }
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/super-admin-removal.php', $schema, $application, $removal->value, $email],
            [0 => ['file', '/dev/null', 'r'], 1 => $output, 2 => $output],
            $pipes,
        );
        if (!is_resource($process)) {
            fclose($output);
            throw new \RuntimeException('Cannot start the removal.');
        }

        return ['process' => $process, 'output' => $output, 'application' => $application];
    }

    /** No web server runs in these processes, so there are no connections to close. */
    private static function noLiveConnections(): LiveConnectionsPortInterface
    {
        return new class () implements LiveConnectionsPortInterface {
            public function closeForUser(Uuid $userId): int
            {
                return 0;
            }

            public function closeForAccessTokens(array $accessTokenIds): int
            {
                return 0;
            }
        };
    }

    /**
     * DoctrineTransaction's boundary without its Swoole service pool handling.
     *
     * @param (callable(): void)|null $beforeCommit Runs inside the transaction, after all of its writes
     */
    private static function transaction(EntityManager $manager, ?callable $beforeCommit): TransactionPortInterface
    {
        return new readonly class ($manager, $beforeCommit) implements TransactionPortInterface {
            /** @param (callable(): void)|null $beforeCommit */
            public function __construct(private EntityManager $manager, private mixed $beforeCommit)
            {
            }

            public function run(callable $operation): mixed
            {
                return $this->manager->getConnection()->transactional(function () use ($operation): mixed {
                    $result = $operation();
                    $this->manager->flush();
                    if ($this->beforeCommit !== null) {
                        ($this->beforeCommit)();
                    }

                    return $result;
                });
            }
        };
    }
}
