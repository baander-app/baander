<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Auth;

use App\Auth\Application\Command\User\SetUserRolesCommand;
use App\Auth\Application\CommandHandler\User\SetUserRolesHandler;
use App\Auth\Application\Service\SuperAdminContinuity;
use App\Auth\Application\Service\UserLookup;
use App\Auth\Infrastructure\Doctrine\UserRepository;
use App\Auth\Infrastructure\Repository\EmailVerificationTokenRepository;
use App\Auth\Infrastructure\Repository\PasswordResetTokenRepository;
use App\Shared\Application\Port\TransactionPortInterface;
use Doctrine\ORM\EntityManager;

/**
 * The production role change over one disposable schema, shared by SuperAdminDemotionRaceTest and
 * the child process it runs (super-admin-demotion.php) as a second PostgreSQL session. The
 * connection and mapping are the OAuth revocation race's: the Auth entities in a schema of their own.
 */
final class SuperAdminDemotionRace
{
    public static function users(EntityManager $manager): UserRepository
    {
        $connection = $manager->getConnection();

        return new UserRepository($manager, new PasswordResetTokenRepository($connection), new EmailVerificationTokenRepository($connection));
    }

    /**
     * Replaces the user's roles through SetUserRolesHandler.
     *
     * @param list<string>            $roles
     * @param (callable(): void)|null $beforeCommit Runs inside the role change's transaction, after its writes
     */
    public static function setRoles(EntityManager $manager, string $email, array $roles, ?callable $beforeCommit = null): void
    {
        $users = self::users($manager);
        $handler = new SetUserRolesHandler(
            new UserLookup($users),
            $users,
            self::transaction($manager, $beforeCommit),
            new SuperAdminContinuity($users),
        );

        $handler(new SetUserRolesCommand($email, $roles));
    }

    /**
     * Starts the child session, which replaces the user's roles and prints the stored roles, or the
     * reason the change was refused.
     *
     * @param list<string> $roles
     *
     * @return array{process: resource, output: resource, application: string}
     */
    public static function start(string $schema, string $email, array $roles): array
    {
        $application = 'super_admin_race_' . (random_bytes(6) |> bin2hex(...));
        $output = tmpfile();
        if ($output === false) {
            throw new \RuntimeException('Cannot create the role change output.');
        }
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/super-admin-demotion.php', $schema, $application, $email, json_encode($roles, JSON_THROW_ON_ERROR)],
            [0 => ['file', '/dev/null', 'r'], 1 => $output, 2 => $output],
            $pipes,
        );
        if (!is_resource($process)) {
            fclose($output);
            throw new \RuntimeException('Cannot start the role change.');
        }

        return ['process' => $process, 'output' => $output, 'application' => $application];
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
