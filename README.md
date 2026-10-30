# Bånder

Bånder is a self-hosted server for organizing and playing a personal media
collection. You provide the media files and run the server; Bånder turns those
files into a catalog you can search, browse and play through a web interface.

The main browser workflows cover music and movies. Music is organized around
artists, albums, songs and genres, with playlists, listening history and a player
that stays available as you browse. Movies have their own catalog and playback
view. Separate user accounts let you give other people access to selected libraries
on your server.

## Explore and organize your collection

A library connects a named collection to a directory of media files. Scanning it
finds files and queues them for ingestion. Workers read their tags and technical
information and add them to the catalog. You can keep collections in separate
libraries and rescan them as files change. Continuous filesystem watching is
available through a separately started watcher.

In the web app, move between artist pages, albums, track lists and genres, or search
for artists, albums and songs directly. Album covers can be extracted from embedded
artwork, so a tagged music collection provides the starting point for browsing.
Recent listening on the home page helps you return to music you have already played.

The [library guide](docs-book/part-1-operator-guide/library-management.md) explains
scanning, supported file formats, cover extraction and file watching.

## Add metadata, artwork and lyrics

Bånder can supplement file tags with information from external services.
MusicBrainz provides the primary music metadata source, Discogs supplies fallback
release information and artwork, and Last.fm supports optional lookups for artist
biographies, tags and related artists. Provider configuration determines which sources are
available; enrichment sends requests to those services.

Lyrics can come from `.lrc` files alongside your tracks or be fetched from LRCLIB.
Automatic metadata synchronization and automatic lyrics fetching for newly indexed
music are optional settings, both off by default. Operators can also start these
jobs explicitly.

See [external APIs](docs-book/part-1-operator-guide/external-apis.md) for provider
setup and the [library guide](docs-book/part-1-operator-guide/library-management.md#what-happens-to-new-music)
for the settings that apply when a scan discovers new music.

## Listen in the web player

The music player includes a playback queue, shuffle and repeat controls. You can
browse the catalog while listening, choose what plays next and return to the
current track without leaving the web app. Playback preferences include crossfade.

The equalizer offers presets and graphic or parametric controls, with saved
profiles for different audio devices. Additional controls include compression,
stereo width and crossfeed, allowing listeners to adjust the sound for their
headphones or speakers.

For clients that need another audio format, the server can transcode tracks to
Opus, AAC or MP3. Audio transcoding must be enabled explicitly. Internet radio is
also available in the web interface, with country browsing and starred stations.

## Build and share playlists

Create playlists by choosing songs yourself, or use smart playlists whose rules
select music from the catalog. Playlists have their own pages for browsing and
playback, and can be shared with other users on the same server. Collaborator
permissions control who can contribute to a shared playlist.

## Browse and play movies

Movies have browse and detail pages with an integrated video player. Background
workers use FFmpeg to prepare video for streaming, while the web server delivers
manifests and media segments to the client. Video transcoding supports multiple
quality tiers; the actual playback formats depend on the worker's encoder
capabilities and the client.

The [transcoding guide](docs-book/part-1-operator-guide/transcoding.md) covers video
and audio conversion, quality settings and execution capacity. Browser codec
support and host resources still matter when choosing a playback setup.

## Manage accounts and access

Bånder supports separate user accounts, administrator-managed library access and
personal settings. Authentication includes password login, passkeys and TOTP
two-factor authentication. Users can manage registered devices and passkeys, while
operators manage accounts and roles.

Notifications can appear in the application or be delivered through configured
email, browser push and webhook channels. Email and push require their respective
service settings and keys; webhooks are managed by administrators.

The [user-management guide](docs-book/part-1-operator-guide/user-management.md)
explains account administration. The [notification guide](docs-book/part-1-operator-guide/notifications.md)
and [security guide](docs-book/part-1-operator-guide/security.md) cover channel setup
and operating an instance with its own credentials.

## How an instance runs

An instance includes the web application, persistent media storage, PostgreSQL,
Redis and a separate background worker deployment. PostgreSQL holds application
data; Redis supports queues and caching. The web process handles requests and
client connections. Workers handle queued jobs, scheduling, notification delivery
and media encoding.

Web and workers need access to the same media paths through shared named volumes.
Source media and generated output have different access requirements. The worker
controller runs on the trusted Docker host and admits separate control and
execution containers with explicit CPU and memory budgets. Read the deployment
prerequisites before choosing hardware: the worker requirements are larger than
those of the web process alone.

Health endpoints, background-job monitoring and console commands help operators
inspect scans, failures and worker availability. The [operator guide](docs-book/part-1-operator-guide/README.md)
connects the system explanation to configuration, monitoring, upgrades and recovery.

## Getting started

Start with the **[operator manual](docs-book/part-1-operator-guide/README.md)**.
Its reading path explains the components and data stores before installation,
then connects routine tasks to monitoring, maintenance and recovery. If you are
ready to install, the [source-installation guide](docs-book/part-1-operator-guide/getting-started.md)
walks through configuration and TLS, shared storage, image builds, database
initialization, OAuth keys, the first administrator account, the frontend build,
worker deployment and a first library scan.

Bånder is a pre-release project. The current setup builds from source and uses a
development-oriented Docker Compose stack. Starting Compose alone does not provide
a complete instance: background processing requires the separately admitted worker
pair explained in [Background Work](docs-book/part-1-operator-guide/background-work.md).

A disposable source-installation check verified frontend loading, administrator
login, worker startup and a sample music scan through ingestion and browser
catalog display. The scanned track played to completion as original FLAC and
transcoded Opus; byte-range requests also passed.
The [roadmap checkpoint](ROADMAP.md#source-installation-documentation)
records the evidence, environment adaptations and verification limits. Other media
categories have incomplete browser workflows; they should not be assumed to offer
the same coverage as music and movies.

## For developers

The backend uses PHP, Symfony and Swoole; the web client uses React and TypeScript.
The HTTP API connects the web interface to library management, catalog queries,
playback and account services.

For local development, complete the one-time DNS and certificate setup in the
[development environment guide](docs-book/part-2-developer-guide/development-environment.md),
then use `make dev-up`, `make dev-down`, `make dev-status`, `make preview-up` and
`make preview-down`. The persistent `baander.test` instance follows remote `master`;
a preview runs the current worktree with separate writable state. Host runtime
qualification remains pending; the guide records the prerequisites and known blockers.

Start with the [developer guide](docs-book/part-2-developer-guide/README.md) for the
architecture and API references, then use the [development environment guide](docs-book/part-2-developer-guide/development-environment.md)
and [frontend guide](docs-book/part-2-developer-guide/frontend-development.md) for
working on the code. The [testing guide](docs-book/part-2-developer-guide/testing.md)
names the current runners and runtime checks. Follow the
[contribution instructions](docs-book/part-2-developer-guide/contributing.md) for
project conventions and the contribution workflow.

## License

Baander's first-party code is licensed under [Apache-2.0](LICENSE.md), with
[package-specific exceptions and third-party notices](THIRD_PARTY.md).
Copyright (C) 2025–2026 Martin Juul Christiansen and contributors.
