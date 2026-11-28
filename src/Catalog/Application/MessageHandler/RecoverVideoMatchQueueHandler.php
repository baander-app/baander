<?php

declare(strict_types=1);

namespace App\Catalog\Application\MessageHandler;

use App\Catalog\Application\Command\RecoverVideoMatchQueueCommand;
use App\Catalog\Application\Message\DrainVideoMatchesMessage;
use App\Catalog\Application\Port\VideoMatchTargetLoaderInterface;
use App\Catalog\Application\Service\VideoMatch\VideoMatchRequester;
use App\Catalog\Domain\Repository\VideoMatchStateRepositoryInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class RecoverVideoMatchQueueHandler
{
    private const int PAGE_SIZE = 100;

    public function __construct(
        private VideoMatchStateRepositoryInterface $states,
        private VideoMatchTargetLoaderInterface $targets,
        private VideoMatchRequester $requester,
        private MessageBusInterface $bus,
        private ClockInterface $clock,
    ) {}

    #[AsMessageHandler]
    public function __invoke(RecoverVideoMatchQueueCommand $command): string
    {
        $cursor = null;
        do {
            $page = $this->states->awaitingProviders(self::PAGE_SIZE, $cursor);
            foreach ($page as $state) {
                $target = $this->targets->load($state);
                if ($target !== null && $this->requester->hasUsableProvider($target)) {
                    $this->requester->request($target);
                }
                $cursor = $state->id;
            }
        } while (count($page) === self::PAGE_SIZE);
        $due = $this->states->nextRequestDueAt();
        if ($due === null || $due > $this->clock->now()) {
            return 'no video match due';
        }
        $this->bus->dispatch(new DrainVideoMatchesMessage());

        return 'video match queue started';
    }
}
