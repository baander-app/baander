<?php

declare(strict_types=1);

namespace App\UserPreference\Infrastructure;

use App\Shared\Domain\Model\Uuid;
use App\UserPreference\Application\Port\SidebarConfigPortInterface;
use App\UserPreference\Domain\Model\SidebarConfig;
use App\UserPreference\Domain\Model\SidebarItem;
use App\UserPreference\Domain\Repository\SidebarConfigRepositoryInterface;

final class SidebarConfigAdapter implements SidebarConfigPortInterface
{
    /**
     * Per-media-type default sections matching frontend schemas exactly.
     * Stored as flat items per section — the controller restructures to sections on read.
     *
     * @var array<string, array<int, array{id: string, type: string, label: string, icon: string, config: array<string, mixed>}>
     */
    private const DEFAULT_ITEMS = [
        'music' => [
            ['id' => 'music-home', 'type' => 'page_link', 'label' => 'Home', 'icon' => 'home', 'config' => ['route' => '/music']],
            ['id' => 'music-browse', 'type' => 'page_link', 'label' => 'Browse', 'icon' => 'disc', 'config' => ['route' => '/music/browse']],
            ['id' => 'music-albums', 'type' => 'page_link', 'label' => 'Albums', 'icon' => 'disc', 'config' => ['route' => '/music/albums']],
            ['id' => 'music-artists', 'type' => 'page_link', 'label' => 'Artists', 'icon' => 'mic-2', 'config' => ['route' => '/music/artists']],
            ['id' => 'music-songs', 'type' => 'page_link', 'label' => 'Songs', 'icon' => 'music', 'config' => ['route' => '/music/songs']],
            ['id' => 'music-genres', 'type' => 'page_link', 'label' => 'Genres', 'icon' => 'tag', 'config' => ['route' => '/music/genres']],
            ['id' => 'music-playlists', 'type' => 'page_link', 'label' => 'Playlists', 'icon' => 'list-music', 'config' => ['route' => '/music/playlists']],
            ['id' => 'music-favorites', 'type' => 'page_link', 'label' => 'Favorites', 'icon' => 'star', 'config' => ['route' => '/music/favorites']],
            ['id' => 'music-radio', 'type' => 'page_link', 'label' => 'Radio', 'icon' => 'radio', 'config' => ['route' => '/music/radio']],
            ['id' => 'music-recommended', 'type' => 'page_link', 'label' => 'Recommended', 'icon' => 'sparkles', 'config' => ['route' => '/music/recommended']],
        ],
        'movies' => [
            ['id' => 'movies-home', 'type' => 'page_link', 'label' => 'Home', 'icon' => 'home', 'config' => ['route' => '/movies']],
            ['id' => 'movies-browse', 'type' => 'page_link', 'label' => 'Browse', 'icon' => 'disc', 'config' => ['route' => '/movies/browse']],
            ['id' => 'movies-items', 'type' => 'page_link', 'label' => 'Movies', 'icon' => 'disc', 'config' => ['route' => '/movies']],
            ['id' => 'movies-directors', 'type' => 'page_link', 'label' => 'Directors', 'icon' => 'mic-2', 'config' => ['route' => '/movies/directors']],
            ['id' => 'movies-genres', 'type' => 'page_link', 'label' => 'Genres', 'icon' => 'tag', 'config' => ['route' => '/movies/genres']],
            ['id' => 'movies-watchlists', 'type' => 'page_link', 'label' => 'Watchlists', 'icon' => 'list-music', 'config' => ['route' => '/movies/watchlists']],
            ['id' => 'movies-favorites', 'type' => 'page_link', 'label' => 'Favorites', 'icon' => 'star', 'config' => ['route' => '/movies/favorites']],
            ['id' => 'movies-recommended', 'type' => 'page_link', 'label' => 'Recommended', 'icon' => 'sparkles', 'config' => ['route' => '/movies/recommended']],
        ],
        'tv' => [
            ['id' => 'tv-home', 'type' => 'page_link', 'label' => 'Home', 'icon' => 'home', 'config' => ['route' => '/tv']],
            ['id' => 'tv-browse', 'type' => 'page_link', 'label' => 'Browse', 'icon' => 'disc', 'config' => ['route' => '/tv/browse']],
            ['id' => 'tv-shows', 'type' => 'page_link', 'label' => 'Shows', 'icon' => 'disc', 'config' => ['route' => '/tv/shows']],
            ['id' => 'tv-networks', 'type' => 'page_link', 'label' => 'Networks', 'icon' => 'mic-2', 'config' => ['route' => '/tv/networks']],
            ['id' => 'tv-genres', 'type' => 'page_link', 'label' => 'Genres', 'icon' => 'tag', 'config' => ['route' => '/tv/genres']],
            ['id' => 'tv-watchlists', 'type' => 'page_link', 'label' => 'Watchlists', 'icon' => 'list-music', 'config' => ['route' => '/tv/watchlists']],
            ['id' => 'tv-favorites', 'type' => 'page_link', 'label' => 'Favorites', 'icon' => 'star', 'config' => ['route' => '/tv/favorites']],
            ['id' => 'tv-recommended', 'type' => 'page_link', 'label' => 'Recommended', 'icon' => 'sparkles', 'config' => ['route' => '/tv/recommended']],
        ],
        'podcasts' => [
            ['id' => 'podcasts-home', 'type' => 'page_link', 'label' => 'Home', 'icon' => 'home', 'config' => ['route' => '/podcasts']],
            ['id' => 'podcasts-browse', 'type' => 'page_link', 'label' => 'Browse', 'icon' => 'disc', 'config' => ['route' => '/podcasts/browse']],
            ['id' => 'podcasts-items', 'type' => 'page_link', 'label' => 'Podcasts', 'icon' => 'disc', 'config' => ['route' => '/podcasts']],
            ['id' => 'podcasts-episodes', 'type' => 'page_link', 'label' => 'Episodes', 'icon' => 'play', 'config' => ['route' => '/podcasts/episodes']],
            ['id' => 'podcasts-subscriptions', 'type' => 'page_link', 'label' => 'Subscriptions', 'icon' => 'list-music', 'config' => ['route' => '/podcasts/subscriptions']],
            ['id' => 'podcasts-discover', 'type' => 'page_link', 'label' => 'Discover', 'icon' => 'sparkles', 'config' => ['route' => '/podcasts/discover']],
            ['id' => 'podcasts-trending', 'type' => 'page_link', 'label' => 'Trending', 'icon' => 'trending-up', 'config' => ['route' => '/podcasts/trending']],
        ],
        'concerts' => [
            ['id' => 'concerts-home', 'type' => 'page_link', 'label' => 'Home', 'icon' => 'home', 'config' => ['route' => '/concerts']],
            ['id' => 'concerts-browse', 'type' => 'page_link', 'label' => 'Browse', 'icon' => 'disc', 'config' => ['route' => '/concerts/browse']],
            ['id' => 'concerts-items', 'type' => 'page_link', 'label' => 'Concerts', 'icon' => 'disc', 'config' => ['route' => '/concerts']],
            ['id' => 'concerts-venues', 'type' => 'page_link', 'label' => 'Venues', 'icon' => 'map-pin', 'config' => ['route' => '/concerts/venues']],
            ['id' => 'concerts-artists', 'type' => 'page_link', 'label' => 'Artists', 'icon' => 'mic-2', 'config' => ['route' => '/concerts/artists']],
            ['id' => 'concerts-favorites', 'type' => 'page_link', 'label' => 'Favorites', 'icon' => 'star', 'config' => ['route' => '/concerts/favorites']],
            ['id' => 'concerts-nearby', 'type' => 'page_link', 'label' => 'Nearby', 'icon' => 'map-pin', 'config' => ['route' => '/concerts/nearby']],
            ['id' => 'concerts-recommended', 'type' => 'page_link', 'label' => 'Recommended', 'icon' => 'sparkles', 'config' => ['route' => '/concerts/recommended']],
        ],
        'ebooks' => [
            ['id' => 'ebooks-home', 'type' => 'page_link', 'label' => 'Home', 'icon' => 'home', 'config' => ['route' => '/ebooks']],
            ['id' => 'ebooks-browse', 'type' => 'page_link', 'label' => 'Browse', 'icon' => 'disc', 'config' => ['route' => '/ebooks/browse']],
            ['id' => 'ebooks-items', 'type' => 'page_link', 'label' => 'Books', 'icon' => 'book', 'config' => ['route' => '/ebooks']],
            ['id' => 'ebooks-authors', 'type' => 'page_link', 'label' => 'Authors', 'icon' => 'mic-2', 'config' => ['route' => '/ebooks/authors']],
            ['id' => 'ebooks-series', 'type' => 'page_link', 'label' => 'Series', 'icon' => 'layers', 'config' => ['route' => '/ebooks/series']],
            ['id' => 'ebooks-shelves', 'type' => 'page_link', 'label' => 'Shelves', 'icon' => 'list-music', 'config' => ['route' => '/ebooks/shelves']],
            ['id' => 'ebooks-reading-lists', 'type' => 'page_link', 'label' => 'Reading Lists', 'icon' => 'book-open', 'config' => ['route' => '/ebooks/reading-lists']],
            ['id' => 'ebooks-recommended', 'type' => 'page_link', 'label' => 'Recommended', 'icon' => 'sparkles', 'config' => ['route' => '/ebooks/recommended']],
        ],
    ];

    private const VALID_MEDIA_TYPES = ['music', 'movies', 'tv', 'podcasts', 'concerts', 'ebooks'];

    public function __construct(
        private readonly SidebarConfigRepositoryInterface $repository,
    ) {
    }

    public function getConfig(Uuid $userId, string $mediaType): ?SidebarConfig
    {
        return $this->repository->findByUserAndMediaType($userId, $mediaType);
    }

    public function getConfigOrDefault(Uuid $userId, string $mediaType): SidebarConfig
    {
        $config = $this->repository->findByUserAndMediaType($userId, $mediaType);

        if ($config !== null) {
            return $config;
        }

        $items = array_map(
            fn (array $data) => SidebarItem::fromArray($data),
            self::DEFAULT_ITEMS[$mediaType] ?? [],
        );

        return SidebarConfig::create($userId, $mediaType, $items);
    }

    public function updateConfig(Uuid $userId, string $mediaType, array $sections): SidebarConfig
    {
        $items = $this->flattenSections($sections);

        $existing = $this->repository->findByUserAndMediaType($userId, $mediaType);

        if ($existing !== null) {
            $existing->updateItems($items);
            $this->repository->save($existing);

            return $existing;
        }

        $config = SidebarConfig::create($userId, $mediaType, $items);
        $this->repository->save($config);

        return $config;
    }

    public function deleteConfig(Uuid $userId, string $mediaType): void
    {
        $config = $this->repository->findByUserAndMediaType($userId, $mediaType);

        if ($config !== null) {
            $this->repository->delete($config);
        }
    }

    /**
     * Flatten a sections array into a flat list of SidebarItems.
     * Each section has: { id, label, type, items: [...] }
     *
     * @param array<int, array<string, mixed>> $sections
     * @return SidebarItem[]
     */
    private function flattenSections(array $sections): array
    {
        $items = [];

        foreach ($sections as $section) {
            $sectionItems = $section['items'] ?? [];
            foreach ($sectionItems as $itemData) {
                $items[] = SidebarItem::fromArray($itemData);
            }
        }

        return $items;
    }
}
