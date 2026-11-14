# Server and User Settings

Administrators and users adjust runtime behavior—such as audio transcoding, automatic metadata or lyrics enrichment, scheduled recommendations, and email language—through database-backed server and user settings without restarting the server.

## Server Settings

A server setting is a named value in the `system_settings` table that an administrator can change while Baander runs. The part of Baander that uses a setting also defines it: its key, its value type, the values it allows and its default. A setting nobody has changed has no row and takes its default. Baander reads a setting each time it acts on it, so a change applies to the next request, message or scheduled run without a restart, including in workers that are already running.

### Changing a setting

The admin panel, the CLI and the API change settings through the same validation:

- **Admin panel.** **Admin → Settings** (`/admin/settings`) lists every server setting by group, with a switch, select or number input and a **Reset** button for each. A setting the server does not act on yet carries a **Not yet enforced** badge. Super administrators can change settings; other administrators see the page read-only, and the admin sidebar links to it only for super administrators.
- **CLI.** [`app:settings:list`, `get`, `set` and `reset`](../commands/app-settings.md).
- **API.** The endpoints below.

| Method | Path | Role | Purpose |
|--------|------|------|---------|
| `GET` | `/api/admin/settings` | `ROLE_ADMIN` | Every server setting with its current value, its stored value and whether the stored value is still allowed |
| `GET` | `/api/admin/settings/definitions` | `ROLE_ADMIN` | The definition of every setting, server and per-user: type, allowed values with labels, default, group and whether it is enforced |
| `PATCH` | `/api/admin/settings` | `ROLE_SUPER_ADMIN` | Change settings; the body is `{"settings": {"<key>": <value>, …}}` |
| `DELETE` | `/api/admin/settings/{key}` | `ROLE_SUPER_ADMIN` | Reset a setting to its default |

`PATCH` checks every key before it writes any. An unknown key or a value its setting does not allow rejects the whole request with `422` and a message for each failing key, and nothing is saved. A malformed body gets `400`. `DELETE` on an unknown key gets `404`.

A stored value can stop being allowed, for example when a setting's allowed values change in a new release. Baander then uses the default; the API reports the stored value with `storedValueValid: false`, and the CLI marks it `(invalid)`. Setting or resetting the setting replaces it.

### Settings reference

| Key | Default | What it controls |
|-----|---------|------------------|
| `admin.can_view_users` | `true` | Whether administrators who are not super administrators may list users. See [Who can manage users](../administration/user-management.md#who-can-manage-users). |
| `admin.can_create_users` | `false` | Whether those administrators may create users. They can create only users with the User role. |
| `i18n.default_language` | `en` | The language of emails to users who have not chosen one: `en` (English), `da` (Dansk) or `th` (ไทย). See [Email language](#email-language). |
| `lyrics.auto_fetch` | `false` | Fetch lyrics from LRCLIB for each new track a scan adds that has no `.lrc` file beside it, and for each track without lyrics that a [rescan](../commands/app-library-scan.md#what-a-rescan-repairs) gives an artist credit or a new length. See [Library Management](../media/library-management.md#what-happens-to-new-music). |
| `metadata.auto_sync` | `false` | Sync each new album a scan adds from the external metadata services. See [Library Management](../media/library-management.md#what-happens-to-new-music). |
| `notifications.admin_alerts` | `true` | Alert administrators when a component's health check reads unhealthy. The web server runs the checks every 60 seconds. See [Health alerts](../administration/notifications.md#health-alerts). |
| `notifications.push_enabled` | `true` | Deliver browser push notifications. See [Notifications](../administration/notifications.md#server-settings). |
| `recommendations.auto_generate` | `false` | Let the daily **Generate recommendations** job generate. See [Scheduled recommendations](#scheduled-recommendations). |
| `transcode.enabled` | `false` | Allow on-the-fly audio transcoding when the startup profile permits encoding. It cannot enable encoding in a `remux` deployment. See [Audio transcoding](../media/transcoding.md#audio-transcoding). |
| `transcode.max_bitrate` | `320` | The highest bitrate of a transcoded audio stream, in kbps: `128`, `192`, `256` or `320`. It is also the bitrate used when a client asks for none. |
| `transcode.aac_implementation` | `bac` | The codec implementation FFmpeg uses for AAC encodes and for decoding AAC sources in them: `bac`, `fdk_aac` or `ffmpeg`. See [AAC implementation](../media/transcoding.md#aac-implementation). |

Every setting in the table is enforced. Four features arrived with server settings and start switched off: audio transcoding, metadata sync for new albums, automatic lyrics fetch, and scheduled recommendations. Turn on the ones you want.

### Scheduled recommendations

A fresh install has an active scheduled job, **Generate recommendations**, that runs every day at 04:00 UTC in incremental mode, which recomputes recommendations for songs updated in the last seven days. Each run reads `recommendations.auto_generate` when it fires. While the setting is off, the run generates nothing, logs that it was skipped, and records `skipped: recommendations.auto_generate is off` as the job's last result.

The setting governs only that job. [`app:recommendation:generate`](../commands/app-recommendation-generate.md), the admin action and schedules an administrator creates always generate.

You can change the job's schedule, pause it, or switch it to full mode in the scheduler admin. A full run loads every song and the listening history of every user, and it runs inside the scheduler worker, whose memory is limited (the [worker deployment manifest](../workers/worker-deployment.md#manifest-format) requires a reservation of at least 320 MiB for it). On a large library it may run out of memory or time, which is why the seeded job is incremental.

### Email language

Baander writes emails in English, Danish or Thai. It picks the language of each email when it sends it:

1. the user's own choice, while Baander still offers that language;
2. otherwise the server default, `i18n.default_language`, while Baander still offers it;
3. otherwise English.

Users choose under **Language** in the Account section of **Settings**. The first option, for example **Server default (Dansk)**, names the current server default; choosing it removes the user's choice, so the user follows the default when an administrator changes it later. Administrators set a user's language in the admin user dialog, and operators with [`app:user:setting`](../commands/app-user-setting.md); see [User settings](../administration/user-management.md#user-settings).

At registration Baander reads the browser's `Accept-Language` header and takes the supported language the browser ranks highest. It ignores `*` and entries with `q=0`, and maps a regional tag such as `da-DK` to `da`. It stores that language as the user's choice only when it differs from the server default: most browsers ask for English, and storing it would tie those users to English after an administrator changes the default. Accounts created by an operator or administrator store no language and follow the server default until one is set for them.

The same choice sets the language of the messages the API returns, such as errors. A signed-in user with a saved choice gets messages in that language. Without a choice, and for requests that are not signed in, the API follows the request's `Accept-Language` header, then falls back to English; the server default applies to emails only.

The language applies to these emails:

- **Password reset and email verification.** Baander looks up the language when it sends the email: after the HTTP response, or at once when the email comes from a console command. If the lookup fails, the email goes out in English and the failure is logged without the address or the link.
- **Notification emails.** The worker looks up the language when it sends each email, so a change made after the notification was queued, such as a new server default, applies to it.

In-app notifications, push notifications and webhook payloads are always in English.

Each language must have a translation of every authentication and notification message before Baander can offer it. The [Shared kernel guide](../../part-2-developer-guide/contexts/shared.md#adding-an-email-language) describes how a language is added.

### User settings

A user setting is a per-user value with the same kind of definition as a server setting. The `user_settings` table holds a row only for an explicit choice; a user without one gets the setting's default, or, for a setting that follows a server setting, that server setting's current value. `language` is currently the only user setting, and it follows `i18n.default_language`.

Signed-in users read and change their own settings through these endpoints:

| Method | Path | Purpose |
|--------|------|---------|
| `GET` | `/api/user/settings` | Every user setting with the user's choice, the value Baander uses, the value after a reset, and where the value comes from |
| `PUT` | `/api/user/settings/{key}` | Store a choice; the body is `{"value": <value>}` |
| `DELETE` | `/api/user/settings/{key}` | Remove the choice, so the setting follows its default |

An unknown key gets `404`, a setting the user may not change gets `403`, and an invalid value gets `422` and stores nothing. Users never read server settings directly; the value after a reset is how the **Language** control learns the current server default. A stored choice that is no longer allowed is presented to the user as no choice.

Administrators read and change a user's settings through the admin API and the CLI; see [User settings](../administration/user-management.md#user-settings).

