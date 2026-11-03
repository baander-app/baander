<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261103120000 extends AbstractMigration
{
    private const string SONG_COLUMNS = 'id,public_id,album_id,title,path,size,mime_type,length,lyrics,track,disc,year,comment,hash,bitrate,sample_rate,channels,codec,explicit,energy,danceability,valence,acousticness,instrumentalness,liveness,spechiness,loudness,mbid,discogs_id,spotify_id,locked_fields,created_at';
    private const string SONG_STREAM_COLUMNS = 'container_format,stream_index';
    private const string RENDITION_COLUMNS = 'rendition_key,track_key,source_signature,source_path,format,bitrate,output_directory,state,generation,partial_path,final_path,error';
    private const string RENDITION_COPY_COLUMNS = 'mode,stream_index,output_container,source_hash';
    private const string JOB_COLUMNS = 'id,public_id,video_id,owner_user_id,quality_tier_name,status,reference_count,total_segments,completed_segments,output_directory,init_segment_path,segment_map,probe_data,video_codec,audio_codec,video_bitrate,audio_bitrate,width,height,framerate,fail_reason,measured_loudness,audio_track_languages,audio_segment_map,created_at';
    private const string JOB_PRESENTATION_COLUMNS = 'request_id,presentation_key,presentation_selection';

    public function getDescription(): string
    {
        return 'Capture source stream and copy presentation column changes for live queries.';
    }

    public function up(Schema $schema): void
    {
        $this->captureTriggers(self::SONG_COLUMNS . ',' . self::SONG_STREAM_COLUMNS, self::RENDITION_COLUMNS . ',' . self::RENDITION_COPY_COLUMNS, self::JOB_COLUMNS . ',' . self::JOB_PRESENTATION_COLUMNS);
    }

    public function down(Schema $schema): void
    {
        $this->captureTriggers(self::SONG_COLUMNS, self::RENDITION_COLUMNS, self::JOB_COLUMNS);
    }

    private function captureTriggers(string $songs, string $renditions, string $jobs): void
    {
        $this->addSql("SET LOCAL lock_timeout = '2s'");
        foreach ([
            'songs' => [$songs, '{catalog,libraries,activity,playlists,recommendations,admin-metadata}', '{album}'],
            'audio_rendition_runtime' => [$renditions, '{admin-media}', '{admin}'],
            'transcode_jobs' => [$jobs, '{admin-media}', '{transcode-owner,admin}'],
        ] as $table => [$columns, $families, $routes]) {
            $arguments = "'{" . $columns . "}', '" . $families . "', '" . $routes . "'";
            $this->addSql("CREATE OR REPLACE TRIGGER query_capture_before BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION query_live_capture_row({$arguments})");
            $this->addSql("CREATE OR REPLACE TRIGGER query_capture_insert AFTER INSERT ON {$table} FOR EACH ROW EXECUTE FUNCTION query_live_capture_row({$arguments})");
        }
    }
}
