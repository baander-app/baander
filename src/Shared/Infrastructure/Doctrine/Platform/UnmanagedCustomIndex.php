<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine\Platform;

/**
 * Database indexes that cannot be represented in Doctrine entity attributes
 * and therefore must not participate in schema comparison.
 *
 * These indexes are still created and managed by migrations — listing them
 * here only suppresses the permanent, spurious drift that {@see doctrine:schema:update}
 * / {@see doctrine:migrations:diff} would otherwise report.
 *
 * Add a case whenever you create a migration-only index that Doctrine cannot
 * model (polymorphic, partial, expression-based, etc.). GIN trigram indexes
 * (`*_trgm`) are matched by suffix in the schema manager and do not need a
 * case here.
 */
enum UnmanagedCustomIndex: string
{
    /** Polymorphic image lookup across (imageable_type, album_id, artist_id, playlist_id). */
    case ImagesImageable = 'idx_images_imageable';

    /** Partial index: albums missing cover art, for backfill jobs. */
    case AlbumsCoverImageNull = 'idx_albums_cover_image_null';
}
