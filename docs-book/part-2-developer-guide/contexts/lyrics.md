# Lyrics

The Lyrics context handles lyrics storage and retrieval. It is a minimal context with only Domain and Infrastructure layers -- no Application or Interface layer exists. Lyrics are fetched and stored as a side effect of metadata enrichment (via the Metadata context) and served to users as a field on song resources (via the Catalog context). There is no direct user interaction with this context.

## Layer Structure

This context has Domain and Infrastructure layers only. There is no Application layer (no commands or use cases) and no Interface layer (no HTTP endpoints, no controllers). The Catalog context reads lyrics through the repository interface and embeds them in song API responses.

## Domain Models

### Aggregate Roots

| Model | Key Properties | Purpose |
|-------|---------------|---------|
| `Lyrics` | song ID, lyrics text, source, language | Stores lyrics for a song with provenance information |

The `source` property tracks where the lyrics originated (e.g., embedded in the audio file, fetched from an external lyrics API, or manually provided).

## Ports

None. The Catalog context accesses lyrics through the repository interface directly (`LyricsRepositoryInterface` in `Domain/Repository/`).

## API Endpoints

None. Lyrics are not exposed through their own endpoints. They appear as a field on song resources in the Catalog context.

## Infrastructure

| Component | Purpose |
|-----------|---------|
| `LyricsService` | Fetches lyrics from external sources and persists them via the repository. Called by the Metadata context during enrichment. |

## Cross-Context Dependencies

| Direction | Context | Relationship |
|-----------|---------|--------------|
| Depends on | Shared | Uses `Uuid` for entity identification |
| Depended on by | Metadata | Stores lyrics during enrichment (calls `LyricsService` after fetching from external APIs) |
| Depended on by | Catalog | Serves lyrics via song resources (reads through `LyricsRepositoryInterface`) |
