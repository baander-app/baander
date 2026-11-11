/* Linked into the unit tests and the internal tools, never into the
 * library: builds the codec's computed tables when the program starts.
 *
 * The library builds them when the first encoder or decoder is created.
 * A unit test or an internal tool may read a table, or call a routine
 * that reads one, without creating either; for those programs the
 * tables are there from the start. */
#include "aac_tables.h"

namespace {
struct TablesAtLoad {
  TablesAtLoad() { aac_tables_init(); }
} g_tables_at_load;  // NOLINT(bugprone-throwing-static-initialization)
}  // namespace
