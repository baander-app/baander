<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

use Doctrine\DBAL\Exception\DriverException;
use Throwable;

/**
 * Recognizes PostgreSQL's refusal of a value over a member-input bound: a check violation (23514)
 * of one of the bound constraints. A violation of any other check is a defect, not a member's
 * input, and is not one.
 */
final class MemberInputBoundViolation
{
    private const string CHECK_VIOLATION = '23514';

    /** The member-input bounds the migrations add; each limit is also validated on the request. */
    public const array CONSTRAINTS = [
        'chk_eq_device_profiles_device_id_length',
        'chk_eq_device_profiles_payload_size',
        'chk_listening_sessions_queue_size',
        'chk_media_activities_last_platform_length',
        'chk_media_activities_last_player_length',
        'chk_playlists_description_length',
        'chk_playlists_name_length',
        'chk_playlists_smart_rules_size',
        'chk_push_subscriptions_auth_key_length',
        'chk_push_subscriptions_endpoint_length',
        'chk_push_subscriptions_public_key_length',
        'chk_push_subscriptions_user_agent_length',
        'chk_radio_sessions_active_stream_url_length',
        'chk_recommendations_name_length',
        'chk_user_sidebar_configs_items_size',
    ];

    public static function matches(Throwable $error): bool
    {
        if (!$error instanceof DriverException || $error->getSQLState() !== self::CHECK_VIOLATION) {
            return false;
        }
        // pdo_pgsql reports the constraint only in the message. Its first line names the relation
        // and the constraint; the DETAIL line after it holds the row's values, which are member
        // input. A server that translates its messages quotes names its own way, so the name is
        // matched as a whole word rather than with its quotes.
        preg_match_all('/\w+/', explode("\n", $error->getMessage(), 2)[0], $words);

        return array_intersect(self::CONSTRAINTS, $words[0]) !== [];
    }
}
