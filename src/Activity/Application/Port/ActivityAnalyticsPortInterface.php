<?php

declare(strict_types=1);

namespace App\Activity\Application\Port;

interface ActivityAnalyticsPortInterface
{
    /**
     * @return array{total_plays: int, unique_tracks: int, unique_artists: int, total_listening_time: int}
     */
    public function getSummary(\DateTimeInterface $from, \DateTimeInterface $to): array;

    /**
     * @return array<int, array{track_name: string, artist_name: string|null, album_name: string|null, play_count: int}>
     */
    public function getTopTracks(\DateTimeInterface $from, \DateTimeInterface $to, int $limit = 10): array;

    /**
     * @return array<int, array{artist_name: string, play_count: int}>
     */
    public function getTopArtists(\DateTimeInterface $from, \DateTimeInterface $to, int $limit = 10): array;

    /**
     * @return array{active_users: int, avg_plays_per_user: float, avg_session_length: float}
     */
    public function getEngagement(\DateTimeInterface $from, \DateTimeInterface $to): array;
}
