<?php

declare(strict_types=1);

namespace App\Catalog\Application\Service\VideoMatch;

use App\Catalog\Application\Message\DrainVideoMatchesMessage;
use App\Catalog\Application\Port\VideoMatchRequestInterface;
use App\Catalog\Application\Port\VideoMatchTargetLoaderInterface;
use App\Catalog\Domain\Repository\VideoMatchStateRepositoryInterface;
use App\Metadata\Application\DTO\Video\VideoKind;
use App\Metadata\Application\DTO\Video\VideoProviderId;
use App\Metadata\Application\Port\VideoMetadataPortInterface;
use App\Shared\Domain\Model\Uuid;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class VideoMatchRequester implements VideoMatchRequestInterface
{
    public function __construct(
        private VideoMatchTargetLoaderInterface $targets,
        private VideoMatchStateRepositoryInterface $states,
        private VideoMetadataPortInterface $providers,
        private LibraryProviderOrderService $orders,
        private MessageBusInterface $bus,
        private ClockInterface $clock,
    ) {}

    public function requestAfterImport(Uuid $libraryId, array $relativePaths): void
    {
        $requested = false;
        foreach ($this->targets->forObservations($libraryId, $relativePaths) as $target) {
            $requested = $this->request($target) || $requested;
        }
        if ($requested) {
            $this->bus->dispatch(new DrainVideoMatchesMessage());
        }
    }

    public function hasUsableProvider(VideoMatchTarget $target): bool
    {
        $request = $target->request;
        $library = $request->libraryId ?? throw new \LogicException('A video match target has a library.');
        $order = $this->orders->get($library)->getProviderIds();
        foreach ($this->providers->usableProviders($request->kind) as $provider) {
            if (in_array($provider->value, $order, true)
                && ($provider !== VideoProviderId::Local || $target->hasTitleNfo())
            ) {
                return true;
            }
        }

        return false;
    }

    public function request(VideoMatchTarget $target): bool
    {
        if ($target->matched) {
            return false;
        }
        $now = $this->clock->now();
        if (!$this->hasUsableProvider($target)) {
            if ($target->request->kind === VideoKind::Series) {
                $this->states->markAwaitingSeries($target->id, 'ingest', $now);
            } else {
                $this->states->markAwaitingMovie($target->id, 'ingest', $now);
            }

            return false;
        }

        return $target->request->kind === VideoKind::Series
            ? $this->states->requestSeries($target->id, 'ingest', $now)
            : $this->states->requestMovie($target->id, 'ingest', $now);
    }
}
