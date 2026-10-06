<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261006260000 extends AbstractMigration
{
    /**
     * Each index's columns lead a wider index on the same table, which serves its lookups and foreign-key cascades.
     *
     * @var array<string, array{string, string}> index => [table, columns]
     */
    private const COVERED_INDEXES = [
        'idx_artist_album_artist_id' => ['artist_album', 'artist_id'], // uniq_artist_album_artist_id_album_id_role
        'idx_artist_song_artist_id' => ['artist_song', 'artist_id'], // uniq_artist_song_artist_id_song_id_role
        'idx_genre_album_genre_id' => ['genre_album', 'genre_id'], // uniq_genre_album_genre_id_album_id
        'idx_genre_movie_genre_id' => ['genre_movie', 'genre_id'], // uniq_genre_movie_genre_id_movie_id
        'idx_job_monitors_status' => ['job_monitors', 'status'], // idx_job_monitors_status_created_at
        'idx_party_members_user_id' => ['party_members', 'user_id'], // uniq_party_members_user_id_session_id
        'idx_playlist_song_playlist_id' => ['playlist_song', 'playlist_id'], // uniq_playlist_song_playlist_id_song_id
        'idx_recommendations_source_type_source_id' => ['recommendations', 'source_type, source_id'], // uniq_recommendations_source_target_name_user_id
        'idx_songs_title' => ['songs', 'title'], // idx_songs_title_id
        'idx_starred_stations_user_id' => ['starred_stations', 'user_id'], // uniq_starred_stations_user_id_station_id
        'idx_transcode_jobs_video_id' => ['transcode_jobs', 'video_id'], // uniq_transcode_jobs_video_id_quality_tier_name
        'idx_user_favorites_user_id' => ['user_favorites', 'user_id'], // uniq_user_favorites_user_id_entity_type_entity_public_id
    ];

    public function getDescription(): string
    {
        return 'Drop twelve B-tree indexes whose columns lead a wider index on the same table.';
    }

    public function up(Schema $schema): void
    {
        foreach (array_keys(self::COVERED_INDEXES) as $index) {
            $this->addSql(sprintf('DROP INDEX %s', $index));
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::COVERED_INDEXES as $index => [$table, $columns]) {
            $this->addSql(sprintf('CREATE INDEX %s ON %s (%s)', $index, $table, $columns));
        }
    }
}
