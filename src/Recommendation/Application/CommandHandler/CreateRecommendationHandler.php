<?php

declare(strict_types=1);

namespace App\Recommendation\Application\CommandHandler;

use App\Recommendation\Application\Command\CreateRecommendationCommand;
use App\Recommendation\Application\Exception\RecommendationConflictException;
use App\Recommendation\Application\RecommendationLimits;
use App\Recommendation\Domain\Model\Recommendation;
use App\Recommendation\Domain\Repository\RecommendationRepositoryInterface;
use App\Recommendation\Domain\ValueObject\RecommendationOrigin;
use App\Shared\Application\Port\TransactionPortInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class CreateRecommendationHandler
{
    public function __construct(
        private readonly RecommendationRepositoryInterface $recommendations,
        private readonly TransactionPortInterface $transaction,
    ) {
    }

    /**
     * @throws RecommendationConflictException when the user already keeps the most member rows
     *                                         allowed, or a row with the same source, target and
     *                                         name
     */
    #[AsMessageHandler]
    public function __invoke(CreateRecommendationCommand $command): Recommendation
    {
        $recommendation = Recommendation::create(
            sourceType: $command->sourceType,
            sourceId: $command->sourceId,
            targetType: $command->targetType,
            targetId: $command->targetId,
            score: $command->score,
            userId: $command->userId,
            name: $command->name,
            position: $command->position,
            origin: RecommendationOrigin::Member,
        );

        // The user's lock makes the user's API writes take turns: each counts what the previous
        // one committed, so none passes the cap, and none inserts between another's existence
        // check and insert.
        $this->transaction->run(function () use ($recommendation, $command): void {
            $this->recommendations->lockMemberRecommendations($command->userId);
            if ($this->recommendations->countWrittenByMember($command->userId) >= RecommendationLimits::MEMBER_ROWS_PER_USER) {
                throw RecommendationConflictException::limitReached();
            }
            if ($this->recommendations->hasDuplicateOf($recommendation)) {
                throw RecommendationConflictException::alreadyStored();
            }

            $this->recommendations->save($recommendation);
        });

        return $recommendation;
    }
}
