# Move existing data to Debian 13 images

This conditional procedure applies to an existing PostgreSQL data volume initialized under Debian 12. Use it with the [standard update](upgrading.md#standard-update); a fresh install does not need this collation repair.

## Moving to the Debian 13 images

The application, worker and database images build on Debian 13 (trixie), which moves glibc from 2.36 to 2.41. PostgreSQL in the Bånder database image takes its default collation from glibc, so an index on text built under the old version may not match the new ordering. A fresh install initializes its data volume under the new version and needs no action.

An existing PostgreSQL data volume, created by a Debian 12 database image, reports a collation version mismatch once the Debian 13 image starts on it. Rebuild its indexes before writers return:

1. **Stop the writers.** Stop web and retire the worker pair as in the
   [standard update](upgrading.md#standard-update). Keep PostgreSQL running on the new image.
   The preset has no state for this step: its `start` runs PostgreSQL on the new
   image together with web and a fresh worker pair, and its `stop` stops PostgreSQL
   too. With the preset, keep incoming traffic drained after `start` and run step 2
   at once; the worker pair runs meanwhile.
2. **Rebuild the indexes and record the new version.** Connect to PostgreSQL
   directly as the database owner, not through the pooler, in the preset's
   `postgres` service container:

   ```bash
   dc exec postgres sh -ec 'psql -v ON_ERROR_STOP=1 --username="$POSTGRES_USER" --dbname="$POSTGRES_DB" -c "REINDEX DATABASE" -c "ALTER DATABASE \"$POSTGRES_DB\" REFRESH COLLATION VERSION"'
   ```

   `REINDEX DATABASE` rebuilds every index of the current database and
   takes longer on a larger library; `REFRESH COLLATION VERSION` then records the
   new glibc version so the mismatch warning stops.
3. **Repeat for other databases.** Run both statements in any other database of
   the same volume that reports the mismatch.
4. **Return the writers.** Start web and a fresh worker pair, then complete the
   [standard update checks](upgrading.md#standard-update). With the preset, they
   already run since `start`; reopen traffic after the checks.
