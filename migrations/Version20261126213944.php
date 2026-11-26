<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261126213944 extends AbstractMigration
{
    private const string REASONS = "'source_color_metadata_unavailable', 'video_pixel_format_unsupported', 'video_bit_depth_unsupported', 'video_hdr_unsupported', 'subtitle_burn_in_required', 'video_codec_unsupported', 'video_dimensions_unsupported', 'audio_codec_unsupported', 'audio_channels_unsupported', 'source_stream_metadata_unavailable', 'audio_stream_selection_requires_remux', 'container_unsupported', 'audio_stream_unavailable', 'audio_transcoding_disabled', 'destination_media_unsupported', 'subtitle_delivery_unsupported', 'browser_rejected_source', 'unknown'";

    public function getDescription(): string
    {
        return 'Retain a destination rejecting media whose file lies outside its library as the playback rejection reason media_unavailable.';
    }

    public function up(Schema $schema): void
    {
        // Replacing the check takes ACCESS EXCLUSIVE on playback_commands and scans it once; the
        // new list holds every value of the old one, so every stored row passes.
        $this->addSql("SET LOCAL lock_timeout = '2s'");
        $this->addSql($this->replaceCheck(self::REASONS . ", 'media_unavailable'"));
    }

    public function down(Schema $schema): void
    {
        $this->addSql("SET LOCAL lock_timeout = '2s'");
        $this->addSql("UPDATE playback_commands SET rejection_reason = 'unknown' WHERE rejection_reason = 'media_unavailable'");
        $this->addSql($this->replaceCheck(self::REASONS));
    }

    private function replaceCheck(string $reasons): string
    {
        return <<<SQL
            ALTER TABLE playback_commands
                DROP CONSTRAINT chk_playback_commands_rejection_reason,
                ADD CONSTRAINT chk_playback_commands_rejection_reason CHECK (rejection_reason IS NULL OR (outcome IS NOT NULL AND outcome = 'rejected' AND rejection_reason IN ({$reasons})))
            SQL;
    }
}
