<?php

declare(strict_types=1);

namespace App\Playlist\Application;

/**
 * The bounds on what a member may store in playlists. The request classes validate the
 * lengths and list sizes, the handlers enforce the counts, and the database checks the
 * stored lengths (chk_playlists_*). A row stored before a bound existed stays readable.
 */
final class PlaylistLimits
{
    public const int NAME_LENGTH = 255;

    public const int DESCRIPTION_LENGTH = 2000;

    public const int SMART_RULES = 50;

    public const int SMART_RULE_VALUE_LENGTH = 255;

    /** The fields and operators the web client's smart rule editor offers. */
    public const array SMART_RULE_FIELDS = ['genre', 'year', 'artist', 'album', 'play_count', 'date_added', 'duration'];

    public const array SMART_RULE_OPERATORS = ['equals', 'not_equals', 'contains', 'greater_than', 'less_than', 'is_empty', 'is_not_empty'];

    public const int PLAYLISTS_PER_OWNER = 1000;

    public const int COLLABORATORS_PER_PLAYLIST = 50;

    public const int SONGS_PER_PLAYLIST = 10000;
}
