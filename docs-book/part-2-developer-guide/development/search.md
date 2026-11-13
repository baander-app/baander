# Search

Baander uses PGroonga (a PostgreSQL extension for full-text search) to power search across catalog entities (albums, artists, songs, and movies). Search is integrated at the repository level via the `PgroongaSearchTrait`.

## How It Works

1. A controller receives a search query and constructs `SearchOptions`
2. The repository method uses `PgroongaSearchTrait` to build a PGroonga query
3. PGroonga performs full-text search with scoring (`pgroonga_score`)
4. Results are returned as domain models, sorted by relevance

Baander uses the PGroonga 2+ API: `&@~` for query-language matching and
`pgroonga_score(tableoid, ctid)` for relevance scores. PGroonga has its own index
access method; it is not a PostgreSQL GIN index. See the
[official reference](https://pgroonga.github.io/reference/) for operator classes,
index options, and version requirements. The database image currently requests
PGroonga package `4.0.5-1`; inspect `pg_extension.extversion` on the target database
rather than assuming the running installation matches the image source.

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

- **`buildScoredQuery()`** — returns entities with relevance scores (`pgroonga_score`)
- **`buildScopedScoredQuery()`** — returns entities with relevance scores restricted by a native SQL visibility and filter predicate
- **`buildFilterQuery()`** — applies text matching as a filter within a query builder listing

Catalog repositories enforce library visibility through `CatalogReadScopeQuery::native()`, delegating `search(SearchOptions $options)` to `searchVisible($options, LibraryReadScope::unrestricted())`:

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
        $nativeScope = CatalogReadScopeQuery::native($scope, 'e.library_id', 'scope_library_ids');
        $result = $this->buildScopedScoredQuery(
            options: $options,
            em: $this->entityManager,
            entityClass: AlbumEntity::class,
            tableName: 'albums',
            searchColumn: 'title',
            whereSql: $nativeScope['sql'],
            parameters: $nativeScope['params'],
        );

        $models = array_map(
            fn(AlbumEntity $entity) => $this->toDomain($entity),
            $result['entities'],
        );

        return SearchResult::create(
            items: $models,
            total: $result['total'],
            highestScore: $result['highestScore'],
        );
    }
}
```

### 3. Keep the mapping and migration index definitions aligned

The existing album index uses the definition below. Do not recreate an applied
index or rewrite migration history; use a reviewed forward migration for changes.
Entity index metadata must retain the same PGroonga flags and options.

```sql
CREATE INDEX idx_albums_title_pgroonga ON albums USING pgroonga (title)
WITH (plugins='token_filters/stem', tokenizer='TokenNgram',
      normalizer='NormalizerAuto', token_filters='TokenFilterStem');
```

## SearchOptions

The `SearchOptions` value object (`src/Shared/Domain/Model/SearchOptions.php`) holds private properties read via getters (`getQuery()`, `getLimit()`, `getOffset()`, `getFilters()`, etc.) and updated via immutable `with*()` methods:

| Property | Type | Description |
|----------|------|-------------|
| `query` | `string` | The search text |
| `fields` | `list<string>` | Specific fields to search (`[]` uses the repository default) |
| `limit` | `int` | Maximum results to return (default `20`, capped at `100`) |
| `offset` | `int` | Number of results to skip (default `0`) |
| `minScore` | `float` | Minimum relevance score threshold (default `0.0`) |
| `filters` | `array<string, mixed>` | Additional filter criteria |
| `cursor` | `?Cursor` | Optional cursor token for cursor-paginated search |
| `sortField` | `?string` | Optional field to sort by |
| `sortOrder` | `string` | Sort direction (`'asc'` or `'desc'`, default `'asc'`) |

When `query` is empty, `buildFilterQuery()` returns all items (no text filter applied). This makes the same repository method work for both search and listing.

## See Also

- [Architecture](../architecture/overview.md) — bounded context overview
- [Coding Conventions](coding-conventions.md) — repository patterns
- [API Reference](api-reference.md) — cursor and offset pagination for search results
