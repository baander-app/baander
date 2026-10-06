# Database Naming

PostgreSQL index and constraint names in Baander follow one scheme: a type prefix, then the table, then the columns. The prefixes are the lower-case forms of those Doctrine generates (`IDX_`, `UNIQ_`, `FK_`). A name therefore says what it covers without a catalog lookup.

## Rules

| Object | Name | Example |
|--------|------|---------|
| Index | `idx_<table>_<columns>` | `idx_party_sessions_host_user_id` |
| Unique index or unique constraint | `uniq_<table>_<columns>` | `uniq_users_email` |
| Foreign key | `fk_<table>_<column>` | `fk_notifications_user_id` |
| Index with a special access method | `idx_<table>_<columns>_<method>` | `idx_songs_title_pgroonga`, `idx_songs_public_id_trgm` |
| Primary key | `<table>_pkey` (PostgreSQL default) | `songs_pkey` |

- List columns in index order, separated by underscores: `idx_notifications_user_id_created_at`.
- The `<method>` suffix covers access methods with no conventional name of their own, such as PGroonga (`pgroonga`) and `pg_trgm` GIN indexes (`trgm`). A B-tree index takes no suffix.
- PostgreSQL truncates identifiers longer than 63 bytes. When a name would exceed that, shorten the column part consistently instead of letting PostgreSQL cut the name.

## Where Names Are Set

- **Migrations** name every index and constraint explicitly. Do not rely on PostgreSQL's generated names (`<table>_<column>_key`, `<table>_<column>_fkey`) or on Doctrine's hashed names (`IDX_…`, `FK_…`).
- **Doctrine mappings** name indexes and unique constraints with the same value as the migration (`#[ORM\Index(name: 'idx_…')]`, `#[ORM\UniqueConstraint(name: 'uniq_…')]`). Schema comparison matches indexes by name. A mapping that omits the migration's name therefore makes `doctrine:schema:validate` and migration diffs report a rename.
- **Cross-context foreign keys** on scalar UUID columns are not visible to Doctrine. Their owning context declares each one in a `ForeignKeyDeclarationProviderInterface` service with the constraint's real name, so schema comparison keeps the database FK. See `src/Notification/Infrastructure/Doctrine/NotificationForeignKeys.php`.

## See Also

- [Coding Conventions](coding-conventions.md#database-conventions) — column types and primary keys
- [Search](search.md) — PGroonga indexes
