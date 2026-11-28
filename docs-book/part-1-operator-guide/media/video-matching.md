# Video matching

Create a `tv_show` or `movie` library, scan its directory, then open **Metadata** in administration to review titles that could not be matched. Numbered episodes and movie files can play before an external provider supplies their details. An unrecognized episode filename stays on the review list until an administrator assigns it. [Library Management](library-management.md#scanning) explains discovery and background ingestion.

## Arrange the files

Register the directory that contains the series folders as the TV library root. For example, register `/storage/media/tv` for this layout:

```text
tv/
  Example Show (2024) {tvdb-12345}/
    tvshow.nfo
    poster.jpg
    Season 01/
      Example Show - S01E01.mkv
      Example Show - S01E02-E03.mkv
    Specials/
      Example Show - S00E01.mkv
    Extras/
      Making Of.mkv
```

These naming forms are recognized:

| File or folder | Meaning |
|----------------|---------|
| `Show/Season 01/Show - S01E03.mkv` | Season 1, episode 3 |
| `Show/Show - 1x03.mkv` | Season 1, episode 3 |
| `Show/Season 01/03.mkv` | Episode 3 in the season named by the folder |
| `Show/Show - S01E01E03.mkv` | One file for episodes 1 and 3 |
| `Show/Show - S01E01-E03.mkv` | One file spanning episodes 1 through 3 |
| `Show/Specials/Show - S00E01.mkv` | Special 1, in season 0 |
| `Anime/Anime - 137.mkv` | Absolute episode 137 |
| `Daily Show/Daily Show 2024-03-14.mkv` | Episode identified by its air date |
| `Movie (1995)/Movie (1995).mkv` | Movie title and year from its folder |
| `Movie (1995).mp4` at the movie library root | Movie title and year from the filename |

Keep absolute-numbered files directly under the series folder. A bare number inside `Season 01` is interpreted as an episode in that season. For an air date, the matched provider must identify exactly one episode on that date; absent or ambiguous dates go to review. An absolute episode uses the provider's absolute numbers; when no absolute ordering exists, placement counts through the aired seasons, excluding specials. These forms follow the [episode parser](../../../src/Catalog/Domain/Service/VideoName/EpisodeNameParser.php) and [placement rules](../../../src/Catalog/Application/Service/VideoMatch/EpisodePlacer.php).

A series folder can carry a year in parentheses or brackets. Provider tags such as `{tvdb-12345}`, `{tmdb-1399}`, `{imdb-tt0944947}` and `[tvdbid-12345]` identify a title without a name search when a provider can resolve that ID. Tags name the work, not the provider that must win: with TheTVDB first, a TMDB tag can resolve to TheTVDB's counterpart. The [name-token parser](../../../src/Catalog/Domain/Service/VideoName/NameTokens.php) defines the accepted forms.

### Extras and samples

Put extras in `Extras`, `Behind The Scenes`, `Deleted Scenes`, `Featurettes`, `Interviews`, `Scenes`, `Shorts`, `Trailers`, `Clips`, `Samples`, `Theme-Music`, `Backdrops` or `Other`. Folder names are case-insensitive. Suffixes such as `Movie-trailer.mkv`, `Movie-featurette.mkv` and `Movie-interview.mkv` also classify extras. A filename containing the separate word `sample` is skipped during ingestion.

Extras appear in their title's extras row. They can keep a file resume position, but do not count toward episode ownership or a series' watched state. Administrators can assign a file to episodes or change its extra kind from the review dialog. The [classification rules](../../../src/Catalog/Domain/Service/VideoName/NameTokens.php) and [extra kinds](../../../src/Catalog/Domain/ValueObject/VideoName/ExtraKind.php) are shared by movie and TV ingestion.

## Choose providers

The default TV order is **TheTVDB, TMDB, Local files**. Movies use **TMDB, Local files**; TheTVDB does not match movies. Change a library's order in its **Video matching** settings. The order always retains every supported provider exactly once, including providers that are currently disabled or have no credential.

In **Metadata → Providers**, administrators can switch a provider off for the whole server. A disabled provider is not contacted, and its stored metadata remains. An empty online credential also makes that provider unavailable for new lookups. Reading provider configuration status does not test the remote API. The [provider switch](../../../src/Metadata/Application/Settings/MetadataProviderSwitches.php) and [video provider registry](../../../src/Metadata/Infrastructure/Video/VideoMetadataProviders.php) enforce these rules.

New unmatched titles are queued after ingestion independently of the music setting `metadata.auto_sync`. With no usable provider, the request waits for configuration to become available. An existing match keeps its provider when files are rescanned, an NFO changes or the library order changes. Metadata sync and the daily refresh use that saved provider and ordering. Use an explicit rematch to replace them. See the [matching requester](../../../src/Catalog/Application/Service/VideoMatch/VideoMatchRequester.php), [refresh service](../../../src/Catalog/Application/Service/VideoMatch/VideoMatchRefresh.php) and [provider-order command](../commands/app-video-providers-order.md).

### Configure online credentials

Supply `TMDB_BEARER_TOKEN` for TMDB and a licensed `TVDB_API_KEY` for TheTVDB in the installation's private configuration. Apply the [configuration lifecycle](../configuration/README.md) so web and workers receive the changes. Both variables default to empty; scanning and local playback remain available without them. The [environment reference](../configuration/environment-variables.md#external-apis) lists the supported settings.

Each installation operator supplies a TheTVDB key licensed for that installation's use. Baander uses key-only v4 login and has no subscriber-PIN setting or per-member PIN flow. TheTVDB's user-supported model requires each end user's own PIN, so a key for that model is not the key-only integration described here. Consult [TheTVDB's v4 key guidance](https://support.thetvdb.com/kb/faq.php?id=81) when arranging access. Keep credentials out of media sidecars, tracked files and command arguments. The [adapter](../../../src/Metadata/Infrastructure/Api/Tvdb/TvdbAdapter.php) sends the key only to its fixed login endpoint.

A failed login is reported as `login_failed`, rather than as an empty search. An outage pauses that provider for 5 minutes, then 15 minutes, then an hour between retries. The review page reports the pause and retry time. Other providers' work continues, but a title waiting on the paused first provider does not silently fall through to another match. See [provider pause storage](../../../src/Catalog/Infrastructure/Doctrine/Repository/VideoProviderPauseRepository.php) and [automatic matching](../../../src/Catalog/Application/Service/VideoMatch/AutomaticVideoMatcher.php).

## Local NFO metadata and artwork

The local provider reads XML beside the media. It recognizes these sidecars:

| Sidecar | XML root or use |
|---------|-----------------|
| `tvshow.nfo` in the series folder | `<tvshow>` with the series title, IDs, plot and cast |
| `season.nfo` | `<season>`; include `<seasonnumber>` to identify the season |
| `Episode filename.nfo` beside the matching episode file | `<episodedetails>` with title, season and episode |
| `movie.nfo` in the movie folder | `<movie>` with movie title, IDs, plot and cast |
| Matching-basename `.nfo` beside a movie at the library root | `<movie>` for that root-level file |
| `poster`, `folder`, `fanart` or `backdrop` image | Title artwork |
| `season01-poster` or `season-specials-poster` image | Season artwork |
| `Episode filename-thumb` image | Still for the matching episode file |

Artwork extensions are `.jpg`, `.jpeg`, `.png` and `.webp`. A multi-episode sidecar may contain consecutive `<episodedetails>` elements. For example:

```xml
<episodedetails>
  <title>First part</title>
  <season>1</season>
  <episode>2</episode>
  <uniqueid type="tvdb">123456</uniqueid>
</episodedetails>
<episodedetails>
  <title>Second part</title>
  <season>1</season>
  <episode>3</episode>
  <uniqueid type="tvdb">123457</uniqueid>
</episodedetails>
```

IDs can use `<uniqueid type="tvdb">`, `tmdb` or `imdb`, or the legacy `<tvdbid>`, `<tmdbid>` and `<imdbid>` elements. Cast members need an IMDb, TMDB or TheTVDB person ID to become Person credits. A name alone does not create an identity. NFO files are limited to 1 MiB, and sidecars and artwork must remain within their library. XML does not fetch remote URLs or expand external entities. Malformed, unreadable or oversized sidecars are ignored. See the [sidecar parser](../../../src/Catalog/Domain/Service/VideoName/SidecarName.php), [NFO reader](../../../src/Metadata/Infrastructure/Local/NfoReader.php) and [local metadata adapter](../../../src/Metadata/Infrastructure/Local/LocalVideoMetadata.php).

When **Local files** is off, matching skips NFO metadata and NFO identity hints, even if an online provider is enabled. Folder tags and online title searches remain usable. Turning Local files back on does not rematch a title that already has a match; use the review workflow when you want to replace it.

## Review and fix a match

Open **Metadata → Video reviews**. Filter by kind, library or reason; the provider-pause banner explains why remote work is waiting. A search runs only when submitted and includes every usable provider. Name searches apply automatically only at a score of at least 90 with a lead of at least 10 points over the runner-up. Native and resolved provider IDs identify the work directly. The [confidence service](../../../src/Catalog/Application/Service/VideoMatch/VideoMatchConfidence.php) defines the scoring threshold.

Use **Search** to choose a provider entry. Applying it records a manual title match, replaces unlocked metadata, resets the series to the new entry's default ordering and returns hand-assigned files to review. In **Ordering**, choose the exact offered ordering ID; two orderings can share a label or kind. Changing ordering preserves hand assignments and moves watched marks with the files. If a provider stops offering the saved ordering, refresh retains the current mapping and reports `ordering_unavailable` instead of switching orderings.

In **Files**, select a placement and assign one or more active episodes of its series, or an extra kind. Episode and file lists are paged independently of provider lookups. A later scan preserves that explicit assignment. A multi-episode file plays as each linked episode; its resume position belongs to the file. The [administration service](../../../src/Catalog/Application/Service/VideoMatch/VideoMatchAdminService.php) and [placement service](../../../src/Catalog/Infrastructure/VideoPlacementService.php) implement these operations.

To rematch a whole library, open its **Video matching** settings and review the title, manual-match and hand-assigned-file counts. Confirmation queues the rematch. Manually matched titles are kept unless **Include manually matched titles** is selected. Rematched titles reset their ordering and return hand-assigned files to review; locked metadata fields remain locked.

The console offers the same operations through [reviews](../commands/app-video-match-reviews.md), [search and edit choices](../commands/app-video-match-search.md), [apply](../commands/app-video-match-apply.md), [ordering](../commands/app-video-match-order.md), [file assignment](../commands/app-video-match-assign.md) and [library rematch](../commands/app-video-match-rematch.md). The library rematch command previews by default; `--apply` requests confirmation, and `--include-hand-matched` includes manual title matches.

## Missing files and member state

When every file of a movie, series, season or episode is unavailable, it disappears from member reads. Director filmography and Person credit feeds apply the same rule to video works, while music credits remain. Once a rescan sees the restored file, its existing title, match, ordering, assignments and member state return. Nothing is deleted to hide an unavailable title. See the [member read query](../../../src/Catalog/Infrastructure/Doctrine/Query/MemberVideoCatalogReadQuery.php) and [credit reads](../../../src/Catalog/Infrastructure/Doctrine/Repository/PeopleReadRepository.php).

For movie and TV libraries, a scan that cannot open its root marks indexed files unavailable. A failure partway through a directory walk preserves their prior availability. Restore the mount or read permissions and rescan. Music retains its existing scan error handling. The [scanner](../../../src/Library/Infrastructure/Scanner/DirectoryScanner.php) distinguishes these cases.

An owned episode has at least one available file. Season tabs show owned and total episode counts, with Specials last. Member resume positions work across original-file and converted delivery. Stop, seek and end observations also update the file's saved position. Playing beyond 90% marks the linked episode or movie watched and clears its resume offer; an extra clears its position without marking the title watched. Marking a season or series watched or unwatched affects its currently owned episodes and clears their file resume positions. Files added later remain unwatched. Progress reported while the member is watching that video in a party is ignored. See [progress handling](../../../src/Activity/Application/CommandHandler/RecordVideoProgressHandler.php) and [watched actions](../../../src/Activity/Application/CommandHandler/SetWatchedHandler.php).

## Attribution

Member pages carrying TMDB metadata show the TMDB logo and the notice “This product uses the TMDB API but is not endorsed or certified by TMDB.” TheTVDB metadata carries a link to TheTVDB.com. These credits identify the stored source of the displayed metadata; see the [attribution component](../../../ui/web/src/features/catalog/components/MetadataAttribution.tsx) and [provider information](../../../src/Metadata/Application/Settings/MetadataProvider.php). Keep the attribution when presenting that metadata in another client, and consult the [TMDB requirements](https://developer.themoviedb.org/docs/faq#what-are-the-attribution-requirements) for its current branding rules.
