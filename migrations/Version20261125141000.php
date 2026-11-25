<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261125141000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Bound the lengths and sizes of member-written playlist, activity, preference, radio and session values.';
    }

    public function up(Schema $schema): void
    {
        // The request classes validate these bounds; the checks hold them for every writer.
        // NOT VALID: new rows and every updated row are checked, a stored row is not, so a value
        // written before the bound existed stays readable and deletable. An update of any column
        // of such a row fails while the stored value is over its bound.
        //
        // Text is bounded in characters, as the validator counts them. jsonb is bounded by
        // pg_column_size, which reads a stored value's size from its varlena header or TOAST
        // pointer without decompressing it, so the check costs nothing on the updates of
        // listening_sessions that leave the queue alone; a stored value counts compressed, a new
        // one uncompressed. Each bound covers the largest value validation accepts, measured on
        // PostgreSQL 18.7: 16384 bytes of compact JSON reach at most 98312 bytes as jsonb (an
        // array of one-digit numbers), 50 smart rules with 255-character values 54608, and a
        // queue of 10000 public ids 250008.
        $checks = [
            'playlists' => [
                'chk_playlists_name_length' => 'char_length(name) <= 255',
                'chk_playlists_description_length' => 'char_length(description) <= 2000',
                'chk_playlists_smart_rules_size' => 'pg_column_size(smart_rules) <= 65536',
            ],
            'media_activities' => [
                'chk_media_activities_last_platform_length' => 'char_length(last_platform) <= 64',
                'chk_media_activities_last_player_length' => 'char_length(last_player) <= 64',
            ],
            'eq_device_profiles' => [
                'chk_eq_device_profiles_device_id_length' => 'char_length(device_id) <= 255',
                'chk_eq_device_profiles_payload_size' => 'pg_column_size(payload) <= 131072',
            ],
            'user_sidebar_configs' => [
                'chk_user_sidebar_configs_items_size' => 'pg_column_size(items) <= 131072',
            ],
            'radio_sessions' => [
                'chk_radio_sessions_active_stream_url_length' => 'char_length(active_stream_url) <= 2048',
            ],
            'listening_sessions' => [
                'chk_listening_sessions_queue_size' => 'pg_column_size(queue) <= 262144',
            ],
        ];
        // Adding a CHECK takes ACCESS EXCLUSIVE even when it skips the scan; fail rather than queue
        // behind a running worker's transaction.
        $this->addSql("SET LOCAL lock_timeout = '2s'");
        foreach ($checks as $table => $constraints) {
            foreach ($constraints as $name => $expression) {
                $this->addSql(sprintf('ALTER TABLE %s ADD CONSTRAINT %s CHECK (%s) NOT VALID', $table, $name, $expression));
            }
        }
    }

    public function down(Schema $schema): void
    {
        foreach ([
            'playlists' => ['chk_playlists_name_length', 'chk_playlists_description_length', 'chk_playlists_smart_rules_size'],
            'media_activities' => ['chk_media_activities_last_platform_length', 'chk_media_activities_last_player_length'],
            'eq_device_profiles' => ['chk_eq_device_profiles_device_id_length', 'chk_eq_device_profiles_payload_size'],
            'user_sidebar_configs' => ['chk_user_sidebar_configs_items_size'],
            'radio_sessions' => ['chk_radio_sessions_active_stream_url_length'],
            'listening_sessions' => ['chk_listening_sessions_queue_size'],
        ] as $table => $constraints) {
            foreach ($constraints as $name) {
                $this->addSql(sprintf('ALTER TABLE %s DROP CONSTRAINT %s', $table, $name));
            }
        }
    }
}
