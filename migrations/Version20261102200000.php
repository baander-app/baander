<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261102200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Retain allowlisted terminal playback compatibility reasons.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SET LOCAL lock_timeout = '2s'");
        $this->addSql('ALTER TABLE playback_commands ADD rejection_reason text DEFAULT NULL');
        $this->addSql("ALTER TABLE playback_commands ADD CONSTRAINT chk_playback_command_rejection_reason CHECK (rejection_reason IS NULL OR (outcome IS NOT NULL AND outcome = 'rejected' AND rejection_reason IN ('source_color_metadata_unavailable', 'video_pixel_format_unsupported', 'video_bit_depth_unsupported', 'video_hdr_unsupported', 'subtitle_burn_in_required', 'video_codec_unsupported', 'video_dimensions_unsupported', 'audio_codec_unsupported', 'audio_channels_unsupported', 'source_stream_metadata_unavailable', 'audio_stream_selection_requires_remux', 'container_unsupported', 'audio_stream_unavailable', 'audio_transcoding_disabled', 'destination_media_unsupported', 'subtitle_delivery_unsupported', 'browser_rejected_source', 'unknown')))");
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Removing rejection reasons loses recorded playback outcomes.');
    }
}
