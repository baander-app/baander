<?php

declare(strict_types=1);

namespace App\Auth\Application\QueryHandler\LoginBlock;

use App\Auth\Application\DTO\LoginBlockPage;
use App\Auth\Application\Query\LoginBlock\ListLoginBlocksQuery;
use App\Auth\Domain\Repository\LoginBlockRepositoryInterface;
use App\Shared\Application\Exception\InvalidInputException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** Lists the login honeypot's blocks for the admin panel and `app:login-block:list`. */
final readonly class ListLoginBlocksHandler
{
    public function __construct(
        private LoginBlockRepositoryInterface $blocks,
    ) {
    }

    /** @throws InvalidInputException when the page is out of range */
    #[AsMessageHandler]
    public function __invoke(ListLoginBlocksQuery $query): LoginBlockPage
    {
        if ($query->limit < 1 || $query->limit > ListLoginBlocksQuery::MAX_LIMIT) {
            throw new InvalidInputException(sprintf('limit must be between 1 and %d.', ListLoginBlocksQuery::MAX_LIMIT));
        }
        if ($query->offset < 0) {
            throw new InvalidInputException('offset must be 0 or more.');
        }

        return new LoginBlockPage(
            array_values($this->blocks->findRecent($query->limit, $query->offset)),
            $this->blocks->countRecent(),
        );
    }
}
