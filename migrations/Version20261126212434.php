<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261126212434 extends AbstractMigration
{
    /**
     * The jsonb bounds, measured by the bytes of the value's text form. Each covers the largest
     * value validation accepts, measured on PostgreSQL 18.7: 50 smart rules whose 255-character
     * values are control characters, each printed as a six-character \u escape, reach 79800 bytes;
     * 16384 bytes of compact JSON reach 598780 as an array of 1820 copies of 5.0e-324, which jsonb
     * stores as a numeric and prints with all of its 325 decimals; a queue of 10000 public ids
     * prints as 250000.
     */
    private const array TEXT_FORM_BOUNDS = [
        'playlists' => ['chk_playlists_smart_rules_size', 'smart_rules', 81920],
        'eq_device_profiles' => ['chk_eq_device_profiles_payload_size', 'payload', 655360],
        'user_sidebar_configs' => ['chk_user_sidebar_configs_items_size', 'items', 655360],
        'listening_sessions' => ['chk_listening_sessions_queue_size', 'queue', 262144],
    ];

    /** Every member-input bound, by table. */
    private const array BOUNDS = [
        'playlists' => ['chk_playlists_name_length', 'chk_playlists_description_length', 'chk_playlists_smart_rules_size'],
        'media_activities' => ['chk_media_activities_last_platform_length', 'chk_media_activities_last_player_length'],
        'eq_device_profiles' => ['chk_eq_device_profiles_device_id_length', 'chk_eq_device_profiles_payload_size'],
        'user_sidebar_configs' => ['chk_user_sidebar_configs_items_size'],
        'radio_sessions' => ['chk_radio_sessions_active_stream_url_length'],
        'listening_sessions' => ['chk_listening_sessions_queue_size'],
        'push_subscriptions' => [
            'chk_push_subscriptions_endpoint_length',
            'chk_push_subscriptions_public_key_length',
            'chk_push_subscriptions_auth_key_length',
            'chk_push_subscriptions_user_agent_length',
        ],
        'recommendations' => ['chk_recommendations_name_length'],
    ];

    /** Each repair statement adds the rows it changed or deleted per bound; postUp() reports the sums. */
    private const string REPAIRS = 'member_input_bound_repairs';

    public function getDescription(): string
    {
        return 'Measure the member-input jsonb bounds by the value\'s text form, repair every stored row over a member-input bound, and validate the bounds.';
    }

    public function up(Schema $schema): void
    {
        // The bounds were added NOT VALID, which skips only the scan of stored rows: every new row
        // version is checked, so an update of any column of an over-bound row fails, and with it the
        // ON DELETE SET NULL cascades into such a row, the album and artist merges' bulk updates and
        // the playback relay's session rewrite, none of which a member can answer. The repair changes
        // the stored rows so that every bound can be validated.
        //
        // pg_column_size() reads a stored jsonb value's compressed size and a new value's raw size, so
        // a stored value could pass VALIDATE and still fail every rewrite. The text form's length
        // depends on the value alone. Measuring it detoasts and prints the value on each new row
        // version: about half a millisecond per update of a 250000-byte queue on PostgreSQL 18.7, and
        // every update of listening_sessions rewrites the queue anyway.
        //
        // ALTER TABLE takes ACCESS EXCLUSIVE on the four jsonb tables until the commit; fail rather
        // than queue behind a running writer, and run the migration again.
        $this->addSql("SET LOCAL lock_timeout = '2s'");
        $this->addSql(sprintf(
            'CREATE TEMPORARY TABLE %s (constraint_name text NOT NULL, repaired bigint NOT NULL) ON COMMIT DROP',
            self::REPAIRS,
        ));
        foreach (self::TEXT_FORM_BOUNDS as $table => [$name, $column, $bound]) {
            $this->addSql(sprintf(
                'ALTER TABLE %1$s DROP CONSTRAINT %2$s, ADD CONSTRAINT %2$s CHECK (octet_length(%3$s::text) <= %4$d) NOT VALID',
                $table,
                $name,
                $column,
                $bound,
            ));
        }

        // Each repair writes the whole row once, so the new version passes every bound of its table.
        // Text keeps its first characters (left() counts characters, as the checks do). JSON
        // configuration over its bound returns to its column default. A playlist's version moves, so
        // a save that read the row before the repair fails its compare-and-set instead of writing the
        // old values back. The capture triggers stay on: open clients refetch what changed.
        $this->repair(<<<'SQL'
            UPDATE playlists
            SET name = left(name, 255),
                description = left(description, 2000),
                smart_rules = CASE WHEN octet_length(smart_rules::text) <= 81920 THEN smart_rules ELSE '{}'::jsonb END,
                persistence_version = persistence_version + 1
            WHERE char_length(name) > 255 OR char_length(description) > 2000 OR octet_length(smart_rules::text) > 81920
            RETURNING char_length(old.name) > 255 AS chk_playlists_name_length,
                char_length(old.description) > 2000 AS chk_playlists_description_length,
                octet_length(old.smart_rules::text) > 81920 AS chk_playlists_smart_rules_size
            SQL, 'chk_playlists_name_length', 'chk_playlists_description_length', 'chk_playlists_smart_rules_size');
        $this->repair(<<<'SQL'
            UPDATE media_activities
            SET last_platform = left(last_platform, 64), last_player = left(last_player, 64)
            WHERE char_length(last_platform) > 64 OR char_length(last_player) > 64
            RETURNING char_length(old.last_platform) > 64 AS chk_media_activities_last_platform_length,
                char_length(old.last_player) > 64 AS chk_media_activities_last_player_length
            SQL, 'chk_media_activities_last_platform_length', 'chk_media_activities_last_player_length');
        $this->repair(<<<'SQL'
            UPDATE eq_device_profiles
            SET device_id = left(device_id, 255),
                payload = CASE WHEN octet_length(payload::text) <= 655360 THEN payload ELSE '{}'::jsonb END
            WHERE char_length(device_id) > 255 OR octet_length(payload::text) > 655360
            RETURNING char_length(old.device_id) > 255 AS chk_eq_device_profiles_device_id_length,
                octet_length(old.payload::text) > 655360 AS chk_eq_device_profiles_payload_size
            SQL, 'chk_eq_device_profiles_device_id_length', 'chk_eq_device_profiles_payload_size');
        $this->repair(<<<'SQL'
            UPDATE user_sidebar_configs SET items = '[]'::jsonb
            WHERE octet_length(items::text) > 655360
            RETURNING true AS chk_user_sidebar_configs_items_size
            SQL, 'chk_user_sidebar_configs_items_size');
        $this->repair(<<<'SQL'
            UPDATE radio_sessions SET active_stream_url = left(active_stream_url, 2048)
            WHERE char_length(active_stream_url) > 2048
            RETURNING true AS chk_radio_sessions_active_stream_url_length
            SQL, 'chk_radio_sessions_active_stream_url_length');
        // A queue keeps the entries nearest its current one, in queue order, while its text form fits
        // the bound: each entry costs its own text and the two bytes of its separator, and the last
        // entry's separator pays for the brackets. The current entry stays current; an index past
        // the end counts as the last entry. The revision moves, so a control sent with an index into
        // the old queue is refused as stale.
        $this->repair(<<<'SQL'
            WITH over AS (
                SELECT id, queue, least(greatest(current_track_index, 0), jsonb_array_length(queue) - 1) AS current
                FROM listening_sessions
                WHERE octet_length(queue::text) > 262144
            ), entry AS (
                SELECT over.id, item.position - 1 AS index, item.value, over.current,
                    sum(octet_length(item.value::text) + 2) OVER (
                        PARTITION BY over.id ORDER BY abs(item.position - 1 - over.current), item.position
                    ) AS reach
                FROM over, jsonb_array_elements(over.queue) WITH ORDINALITY AS item(value, position)
            ), kept AS (
                SELECT id, jsonb_agg(value ORDER BY index) AS queue, count(*) FILTER (WHERE index < current) AS current
                FROM entry
                WHERE reach <= 262144
                GROUP BY id
            )
            UPDATE listening_sessions session
            SET queue = coalesce(kept.queue, '[]'::jsonb), current_track_index = coalesce(kept.current, 0),
                revision = session.revision + 1
            FROM over LEFT JOIN kept ON kept.id = over.id
            WHERE session.id = over.id
            RETURNING true AS chk_listening_sessions_queue_size
            SQL, 'chk_listening_sessions_queue_size');
        // A cut endpoint or key cannot deliver a push, so such a subscription is deleted; the device
        // subscribes again. A cut User-Agent label is kept.
        $this->repair(<<<'SQL'
            DELETE FROM push_subscriptions
            WHERE char_length(endpoint) > 2048 OR char_length(public_key) > 128 OR char_length(auth_key) > 64
            RETURNING char_length(endpoint) > 2048 AS chk_push_subscriptions_endpoint_length,
                char_length(public_key) > 128 AS chk_push_subscriptions_public_key_length,
                char_length(auth_key) > 64 AS chk_push_subscriptions_auth_key_length,
                char_length(user_agent) > 255 AS chk_push_subscriptions_user_agent_length
            SQL, 'chk_push_subscriptions_endpoint_length', 'chk_push_subscriptions_public_key_length', 'chk_push_subscriptions_auth_key_length', 'chk_push_subscriptions_user_agent_length');
        $this->repair(<<<'SQL'
            UPDATE push_subscriptions SET user_agent = left(user_agent, 255)
            WHERE char_length(user_agent) > 255
            RETURNING true AS chk_push_subscriptions_user_agent_length
            SQL, 'chk_push_subscriptions_user_agent_length');
        // Names cut to 255 characters can collide on the unique index, so a longer name's row is
        // deleted.
        $this->repair(<<<'SQL'
            DELETE FROM recommendations WHERE char_length(name) > 255
            RETURNING true AS chk_recommendations_name_length
            SQL, 'chk_recommendations_name_length');

        foreach (self::BOUNDS as $table => $names) {
            $this->addSql(sprintf(
                'ALTER TABLE %s %s',
                $table,
                implode(', ', array_map(static fn (string $name): string => 'VALIDATE CONSTRAINT ' . $name, $names)),
            ));
        }
    }

    /** Reports, per bound, how many rows the repair changed or deleted. */
    public function postUp(Schema $schema): void
    {
        // A dry run plans the statements without running them, so it created no counts.
        if ($this->connection->fetchOne("SELECT to_regclass('pg_temp." . self::REPAIRS . "')") === null) {
            return;
        }
        $counts = $this->connection->fetchAllKeyValue(
            'SELECT constraint_name, sum(repaired) FROM pg_temp.' . self::REPAIRS . ' GROUP BY constraint_name ORDER BY constraint_name',
        );
        foreach ($counts as $name => $count) {
            $this->write(sprintf('%s: %d %s over the bound repaired', $name, $count, (int) $count === 1 ? 'row' : 'rows'));
        }
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('The repaired values cannot be restored, and every member-input bound now holds for the stored rows.');
    }

    /**
     * Runs a repair statement whose RETURNING clause names, per bound, whether the row it changed or
     * deleted was over that bound, and adds the counts to the report.
     */
    private function repair(string $statement, string ...$bounds): void
    {
        $counts = array_map(
            static fn (string $bound): string => sprintf("SELECT '%1\$s', count(*) FILTER (WHERE %1\$s) FROM repaired", $bound),
            $bounds,
        );
        $this->addSql(sprintf(
            "WITH repaired AS (\n%s\n)\nINSERT INTO %s (constraint_name, repaired)\n%s",
            $statement,
            self::REPAIRS,
            implode("\nUNION ALL ", $counts),
        ));
    }
}
