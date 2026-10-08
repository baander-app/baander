<?php

declare(strict_types=1);

namespace App\Auth\Application\QueryHandler\User;

use App\Auth\Application\DTO\UserPage;
use App\Auth\Application\Query\User\ListUsersQuery;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Shared\Application\Exception\InvalidInputException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** Lists users for the admin panel and `app:user:list`. */
final readonly class ListUsersHandler
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
    ) {
    }

    /** @throws InvalidInputException when the role is unknown or the page is out of range */
    #[AsMessageHandler]
    public function __invoke(ListUsersQuery $query): UserPage
    {
        if ($query->role !== null && !in_array($query->role, User::ROLES, true)) {
            throw new InvalidInputException(sprintf('role must be one of: %s.', implode(', ', User::ROLES)));
        }
        if ($query->limit < 1 || $query->limit > ListUsersQuery::MAX_LIMIT) {
            throw new InvalidInputException(sprintf('limit must be between 1 and %d.', ListUsersQuery::MAX_LIMIT));
        }
        if ($query->offset < 0) {
            throw new InvalidInputException('offset must be 0 or more.');
        }

        return new UserPage(
            $this->userRepository->findAll($query->role, $query->disabled, $query->limit, $query->offset),
            $this->userRepository->count($query->role, $query->disabled),
        );
    }
}
