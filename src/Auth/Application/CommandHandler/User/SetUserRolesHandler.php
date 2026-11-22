<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\User;

use App\Auth\Application\Command\User\SetUserRolesCommand;
use App\Auth\Application\Exception\LastSuperAdminException;
use App\Auth\Application\Exception\UserNotFoundException;
use App\Auth\Application\Service\SuperAdminContinuity;
use App\Auth\Application\Service\UserLookup;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Port\TransactionPortInterface;
use InvalidArgumentException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Replaces a user's roles with the given set. Setting the roles the user already has
 * succeeds without change. Roles without ROLE_SUPER_ADMIN are refused for the install's
 * only enabled super admin.
 */
final readonly class SetUserRolesHandler
{
    public function __construct(
        private UserLookup $userLookup,
        private UserRepositoryInterface $userRepository,
        private TransactionPortInterface $transaction,
        private SuperAdminContinuity $superAdminContinuity,
    ) {
    }

    /**
     * @return User the user with the new roles
     *
     * @throws UserNotFoundException when no user has the email address or public ID
     * @throws InvalidInputException when no role is given or a role is unknown
     * @throws LastSuperAdminException when the roles would demote the only enabled super admin
     */
    #[AsMessageHandler]
    public function __invoke(SetUserRolesCommand $command): User
    {
        $user = $this->userLookup->byIdentifier($command->identifier);
        $before = $user->getRoles();

        try {
            $user->replaceRoles($command->roles);
        } catch (InvalidArgumentException $exception) {
            throw new InvalidInputException($exception->getMessage(), ['roles' => [$exception->getMessage()]], $exception);
        }

        if ($user->getRoles() === $before) {
            return $user;
        }

        $this->transaction->run(function () use ($user): void {
            // The check reads the stored roles under its lock, so it runs whenever the new roles
            // leave ROLE_SUPER_ADMIN out, whatever roles the user was loaded with.
            if (!$user->hasRole('ROLE_SUPER_ADMIN')) {
                $this->superAdminContinuity->assertAnotherRemains($user);
            }
            $this->userRepository->save($user);
        });

        return $user;
    }
}
