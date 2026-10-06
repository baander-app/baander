# Database Naming

PostgreSQL index and constraint names in Baander follow one scheme: a type prefix, then the table, then the columns. The prefixes are the lower-case forms of those Doctrine generates (`IDX_`, `UNIQ_`, `FK_`). A name therefore says what it covers without a catalog lookup.

## Rules

| Object | Name | Example |
|--------|------|---------|
| Index | `idx_<table>_<columns>` | `idx_party_sessions_host_user_id` |
| Unique index or unique constraint | `uniq_<table>_<columns>` | `uniq_users_email` |
| Foreign key | `fk_<table>_<columns>` | `fk_notifications_user_id` |
| Index with a non-B-tree access method or an extension operator class | `idx_<table>_<columns>_<suffix>` | `idx_songs_title_pgroonga`, `idx_songs_public_id_trgm` |
| Migration-only partial, expression or polymorphic index | `idx_<table>_<purpose>`, or `uniq_<table>_<purpose>` when unique | `idx_albums_cover_image_null`, `idx_images_imageable`, `uniq_genres_name_lower` |
| Primary key | `<table>_pkey` (PostgreSQL default) | `songs_pkey` |

- List columns in index order, separated by underscores: `idx_notifications_user_id_created_at`.
- A B-tree index takes no suffix. The suffixes for other indexes are defined in [Extension Indexes](#extension-indexes).
- A partial index declared on an entity with `options: ['where' => ...]` is not migration-only and takes the column name: `idx_scheduled_jobs_recovery_after_id`.
- Renaming a unique constraint also renames the index that backs it, so both carry the `uniq_` name.
- PostgreSQL truncates identifiers longer than 63 bytes. When a name would exceed that, shorten the column part consistently instead of letting PostgreSQL cut the name. Collapse each polymorphic `<x>_type`, `<x>_id` pair to `<x>`: the unique index on `recommendations (source_type, source_id, target_type, target_id, name, user_id)` is `uniq_recommendations_source_target_name_user_id`.

## Extension Indexes

The initial migration enables `citext`, `ltree`, `pg_stat_statements`, `pg_trgm`, `pgcrypto`, `pgroonga` and `uuid-ossp`. Only some of them affect index or constraint names.

| Extension | Index use in Baander | Naming |
|-----------|----------------------|--------|
| `pgroonga` | Full-text search on catalog titles and names | `_pgroonga` suffix; see [PGroonga](#pgroonga) |
| `pg_trgm` | GIN `gin_trgm_ops` indexes on `public_id` columns | `_trgm` suffix |
| `citext` | `users.email` and `password_reset_tokens.email` are `CITEXT` | Ordinary `idx_`/`uniq_` names; a unique index on a `CITEXT` column is case-insensitive |
| `ltree` | Enabled; no column uses it | `_ltree` suffix for an index using an `ltree` operator class |
| `pgcrypto`, `uuid-ossp`, `pg_stat_statements` | Functions and statistics only | No index naming |

Core access methods without an extension take the method as the suffix: `_gin`, `_gist`, `_brin`, `_hash`, `_spgist`. No such index exists today.

The `_trgm` suffix is functional, not only descriptive. `BaanderPostgreSQLSchemaManager` hides every index whose name ends in `_trgm` from schema comparison, because ORM SchemaTool drops a non-unique trigram index when a unique constraint covers the same column. A trigram index with another name would therefore surface as drift. Other migration-only indexes that Doctrine cannot model are hidden by name: add a case to `UnmanagedCustomIndex` when you create one.

## PGroonga

A PGroonga index name records the search it serves, because the access method alone does not: PGroonga provides different operator classes for full-text, prefix and regular-expression search, and an index's tokenizer options change what it matches.

The database image installs PGroonga package `4.0.5-1`. The operator classes and options below are those of PGroonga 4; check a new option against that release before using it, and confirm the installed version with `pg_extension.extversion` on the target database.

| Index | Name | Operator class | Options |
|-------|------|----------------|---------|
| Full-text search (the project standard) | `idx_<table>_<columns>_pgroonga` | Default for the column type (`pgroonga_text_full_text_search_ops_v2` for `text`) | `plugins='token_filters/stem', tokenizer='TokenNgram', normalizer='NormalizerAuto', token_filters='TokenFilterStem'` |
| Prefix or exact-term search | `idx_<table>_<columns>_pgroonga_prefix` | `pgroonga_text_term_search_ops_v2` | No project standard; set per index |
| Regular-expression search | `idx_<table>_<columns>_pgroonga_regexp` | `pgroonga_text_regexp_ops_v2` | No project standard; set per index |
| Full-text search with non-standard tokenizer options | `idx_<table>_<columns>_pgroonga_<tokenizer>` | Default for the column type | Named by the tokenizer, e.g. `_pgroonga_mecab` |

- The unsuffixed `_pgroonga` name means exactly the standard full-text index. The five existing catalog indexes (`idx_albums_title_pgroonga`, `idx_artists_name_pgroonga`, `idx_genres_name_pgroonga`, `idx_movies_title_pgroonga`, `idx_songs_title_pgroonga`) follow it.
- Use the operator class for the actual column type. `varchar`, array and `jsonb` columns have their own operator classes; see the [PGroonga reference](https://pgroonga.github.io/reference/).
- Declare the index on the entity with the same name, `flags: ['pgroonga']` and the options under `with`, as `AlbumEntity` does. `BaanderPostgreSQLPlatform` renders the flag as `USING pgroonga`, `options.operator_class` as the operator class, and `options.with` as the `WITH (...)` clause.
- Schema comparison does not capture every PGroonga option. A clean `doctrine:schema:validate` does not prove the tokenizer or normalizer; inspect `pg_get_indexdef()` to verify them.

[Search](search.md) describes how repositories query these indexes.

## Where Names Are Set

- **Migrations** name every index and constraint explicitly. Do not rely on PostgreSQL's generated names (`<table>_<column>_key`, `<table>_<column>_fkey`) or on Doctrine's hashed names (`IDX_…`, `FK_…`).
- **Doctrine mappings** name indexes and unique constraints with the same value as the migration (`#[ORM\Index(name: 'idx_…')]`, `#[ORM\UniqueConstraint(name: 'uniq_…')]`). Schema comparison matches indexes by name. A mapping that omits the migration's name therefore makes `doctrine:schema:validate` and migration diffs report a rename.
- **Cross-context foreign keys** on scalar UUID columns are not visible to Doctrine. Their owning context declares each one in a `ForeignKeyDeclarationProviderInterface` service with the constraint's real name, so schema comparison keeps the database FK. See `src/Notification/Infrastructure/Doctrine/NotificationForeignKeys.php`.
- **Association foreign keys** cannot be named in a mapping. DBAL matches foreign keys by definition, so a migration-chosen `fk_` name does not show up as drift.
- **Implicit foreign-key indexes.** For every mapped or declared foreign key, DBAL adds an index named `IDX_<hash>` unless an index on exactly the same columns already exists. When a migration creates that single-column index itself, declare it on the entity with its `idx_` name, as `SidebarConfigEntity` does; otherwise schema comparison reports a rename to `IDX_<hash>`.

`tests/Integration/DatabaseNamingConventionTest.php` reads the migrated catalog and fails on any foreign key, unique constraint or index whose name breaks these rules. It also checks that schema comparison is clean.

## See Also

- [Coding Conventions](coding-conventions.md#database-conventions) — column types and primary keys
- [Search](search.md) — querying PGroonga indexes
