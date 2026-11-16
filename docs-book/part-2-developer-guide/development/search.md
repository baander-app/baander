# Search

Catalog search across albums, artists, songs, and movies runs on PGroonga, a PostgreSQL extension for full-text search. Repositories build their search queries with `PgroongaSearchTrait`.

## How It Works

1. A controller receives a search query and constructs `SearchOptions`
2. The repository method uses `PgroongaSearchTrait` to build a PGroonga query
3. PGroonga performs full-text search with scoring (`pgroonga_score`)
4. Results are returned as domain models; the scored queries sort them by relevance

Baander uses the PGroonga 2+ API: `&@~` for query-language matching and
`pgroonga_score(tableoid, ctid)` for relevance scores. PGroonga has its own index
access method; it is not a PostgreSQL GIN index. See the
[official reference](https://pgroonga.github.io/reference/) for operator classes,
index options, and version requirements. The database image currently requests
PGroonga package `4.1.0-1`; inspect `pg_extension.extversion` on the target database
rather than assuming the running installation matches the image source. Use 4.1.0 or
later: in 4.0.x, the cleanup that `VACUUM`, `ANALYZE` and autovacuum run can delete the
data of a PGroonga index whose creating transaction has not committed yet, and the index
then fails every lookup until `REINDEX` (upstream issue 1013).

Name PGroonga indexes `idx_<table>_<columns>_pgroonga`, as described in [Database Naming](database-naming.md).

## Making a Context Searchable

### 1. Implement the `Searchable` interface

Add `extends Searchable` to the repository interface:

```php
// src/Catalog/Domain/Repository/AlbumRepositoryInterface.php
interface AlbumRepositoryInterface extends Searchable
{
    // ... existing methods
}
```

The `Searchable` interface requires a `search(SearchOptions $options): SearchResult` method.

### 2. Use `PgroongaSearchTrait` in the repository implementation

The trait provides three query builders:

- **`buildScoredQuery()`** — returns the matching entities ordered by relevance (`pgroonga_score`), with the total count and the highest score
- **`buildScopedScoredQuery()`** — returns the same, restricted by a native SQL predicate for library visibility and filters
- **`buildFilterQuery()`** — applies text matching as a filter within a query builder listing

Catalog repositories enforce library visibility through `CatalogReadScopeQuery::native()`, which builds that predicate from the read scope and the filters. They delegate `search(SearchOptions $options)` to `searchVisible($options, LibraryReadScope::unrestricted())`:

```php
// src/Catalog/Infrastructure/Doctrine/Repository/AlbumRepository.php
final class AlbumRepository implements AlbumRepositoryInterface
{
    use PgroongaSearchTrait;

    public function search(SearchOptions $options): SearchResult
    {
        return $this->searchVisible($options, LibraryReadScope::unrestricted());
    }

    public function searchVisible(SearchOptions $options, LibraryReadScope $scope): SearchResult
    {
        if (!$options->hasQuery()) {
            // Listing mode: a scoped, filtered and sorted DQL query without PGroonga
            // ...
        }

        $predicate = CatalogReadScopeQuery::native($scope, 'album', $options);
        $result = $this->buildScopedScoredQuery(
            $options,
            $this->entityManager,
            AlbumEntity::class,
            'albums',
            'title',
            $predicate['predicate'],
            $predicate['parameters'],
            $predicate['types'],
        );

        $albums = array_map(fn(AlbumEntity $entity) => $this->toDomain($entity), $result['entities']);

        return SearchResult::create($albums, $result['total'], $result['highestScore']);
    }
}
```

### 3. Keep the mapping and migration index definitions aligned

The album index has the definition below. Do not recreate an applied index or
rewrite migration history; change it with a reviewed forward migration. The
entity's index metadata must keep the same PGroonga flags and options.

```sql
CREATE INDEX idx_albums_title_pgroonga ON albums USING pgroonga (title)
WITH (plugins='token_filters/stem', tokenizer='TokenNgram',
      normalizer='NormalizerAuto', token_filters='TokenFilterStem');
```

## SearchOptions

The `SearchOptions` value object (`src/Shared/Domain/Model/SearchOptions.php`) holds private properties. Getters read them (`getQuery()`, `getLimit()`, `getOffset()`, `getFilters()`, etc.), and the `with*()` methods return a changed copy:

| Property | Type | Description |
|----------|------|-------------|
| `query` | `string` | The search text |
| `fields` | `list<string>` | Field names to search (default `[]`); no repository reads it, each searches its own fixed column |
| `limit` | `int` | Maximum results to return (default `50`; the catalog controllers also default to `50` and cap it at `100`) |
| `offset` | `int` | Number of results to skip (default `0`) |
| `minScore` | `float` | Minimum relevance score threshold (default `0.0`); no query applies it |
| `filters` | `list<array{field: string, operator: string, value: mixed}>` | Additional filter criteria |
| `cursor` | `?Cursor` | Optional cursor token for cursor-paginated search |
| `sortField` | `?string` | Optional field to sort by |
| `sortOrder` | `string` | Sort direction (`'asc'` or `'desc'`, default `'asc'`) |

When `query` is empty, `buildFilterQuery()` adds no text condition, so the query returns every item that passes the filters. The song repository's cursor-paginated search, its only user, serves both search and listing this way. The scored builders return an empty result for an empty query, so `AlbumRepository::searchVisible()` answers an empty query with its own listing query.

## See Also

- [Architecture](../architecture/overview.md) — bounded context overview
- [Coding Conventions](coding-conventions.md) — repository patterns
- [API Reference](api-reference.md) — cursor and offset pagination for search results
