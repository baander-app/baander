<?php

declare(strict_types=1);

namespace App\Metadata\Infrastructure\Video;

use App\Metadata\Application\DTO\Video\VideoKind;
use App\Metadata\Application\DTO\Video\VideoProviderId;
use App\Metadata\Application\DTO\Video\VideoProviderProblem;
use App\Metadata\Application\DTO\Video\VideoRequest;
use App\Metadata\Application\DTO\Video\VideoTitle;
use App\Metadata\Application\Port\VideoMetadataPortInterface;
use App\Metadata\Application\Settings\MetadataProvider;
use App\Metadata\Application\Settings\MetadataProviderSwitches;

final class VideoMetadataProviders implements VideoMetadataPortInterface
{
    /** @var array<string, VideoProviderAdapter> */
    private array $adapters = [];

    /** @param iterable<VideoProviderAdapter> $adapters */
    public function __construct(private readonly MetadataProviderSwitches $switches, iterable $adapters)
    {
        foreach ($adapters as $adapter) {
            $this->adapters[$adapter->provider()->value] = $adapter;
        }
    }

    public function providers(VideoKind $kind): array
    {
        return array_values(array_filter(VideoProviderId::cases(), static fn (VideoProviderId $id): bool => $id->serves($kind)));
    }

    public function usableProviders(VideoKind $kind): array
    {
        return array_values(array_filter($this->providers($kind), fn (VideoProviderId $id): bool => $this->availableAdapter($id) instanceof VideoProviderAdapter));
    }

    public function search(VideoProviderId $provider, VideoRequest $request): array|VideoProviderProblem
    {
        if (!$provider->serves($request->kind)) {
            return [];
        }
        $adapter = $this->availableAdapter($provider);
        return $adapter instanceof VideoProviderProblem ? $adapter : $adapter->search($request);
    }

    public function identify(VideoProviderId $provider, VideoRequest $request): VideoTitle|VideoProviderProblem|null
    {
        if (!$provider->serves($request->kind)) {
            return null;
        }
        $adapter = $this->availableAdapter($provider);
        return $adapter instanceof VideoProviderProblem ? $adapter : $adapter->identify($request);
    }

    private function availableAdapter(VideoProviderId $provider): VideoProviderAdapter|VideoProviderProblem
    {
        if (!$this->switches->enabled(MetadataProvider::from($provider->value))) {
            return VideoProviderProblem::Disabled;
        }
        $adapter = $this->adapters[$provider->value] ?? null;
        return $adapter !== null && $adapter->isConfigured() ? $adapter : VideoProviderProblem::NotConfigured;
    }
}
