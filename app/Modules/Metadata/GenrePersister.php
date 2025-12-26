<?php

declare(strict_types=1);

namespace App\Modules\Metadata;

use App\Models\Genre;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;

/**
 * Service for handling database persistence of genre hierarchies.
 *
 * This service provides methods to create, update, and manage genre records
 * with their hierarchical parent-child relationships. It supports operations
 * like persisting genre hierarchies, retrieving genre trees, and finding
 * descendants/ancestors of specific genres.
 */
class GenrePersister
{
    /**
     * Create a new GenrePersister instance.
     *
     * @param LoggerInterface $logger Logger for tracking operations and errors
     */
    public function __construct(
        private LoggerInterface $logger
    ) {}

    /**
     * Persist a genre hierarchy to the database.
     *
     * Creates or updates genre records based on the provided hierarchy data.
     * Supports linking parent-child relationships and various persistence options.
     *
     * @param array $hierarchyData GenreHierarchyService output format:
     *                             [
     *                                 'genre_details' => [...],
     *                                 'relationships' => [...]
     *                             ]
     * @param array $options Persistence options:
     *                       - 'update_existing' (bool): Update records if they exist (default: false)
     *                       - 'delete_orphans' (bool): Delete genres not in hierarchy (default: false)
     * @return array Statistics array with keys: created, updated, linked, errors
     */
    public function persistHierarchy(array $hierarchyData, array $options = []): array
    {
        $updateExisting = $options['update_existing'] ?? false;
        $deleteOrphans = $options['delete_orphans'] ?? false;

        $stats = [
            'created' => 0,
            'updated' => 0,
            'linked' => 0,
            'errors' => [],
        ];

        DB::beginTransaction();

        try {
            $genreDetails = $hierarchyData['genre_details'] ?? [];
            $relationships = $hierarchyData['relationships'] ?? [];

            // Step 1: Create/update all genre records
            $genreMap = [];
            foreach ($genreDetails as $genreName => $details) {
                $genre = Genre::where('name', $genreName)->first();

                if (!$genre) {
                    $genre = Genre::create([
                        'name' => $genreName,
                        'slug' => Str::slug($genreName),
                        'mbid' => $details['musicbrainz']['mbid'] ?? null,
                    ]);
                    $stats['created']++;
                    $this->logger->debug("Created genre: {$genreName}");
                } elseif ($updateExisting) {
                    $genre->update([
                        'mbid' => $details['musicbrainz']['mbid'] ?? $genre->mbid,
                    ]);
                    $stats['updated']++;
                    $this->logger->debug("Updated genre: {$genreName}");
                }

                $genreMap[$genreName] = $genre->id;
            }

            // Step 2: Link parent-child relationships
            foreach ($relationships as $relationship) {
                $childName = $relationship['child'];
                $parentName = $relationship['parent'];

                if (!isset($genreMap[$childName]) || !isset($genreMap[$parentName])) {
                    $this->logger->warning("Skipping relationship - missing genre", [
                        'child' => $childName,
                        'parent' => $parentName,
                    ]);
                    $stats['errors'][] = "Missing genre for relationship: {$parentName} -> {$childName}";
                    continue;
                }

                $childId = $genreMap[$childName];
                $parentId = $genreMap[$parentName];

                Genre::where('id', $childId)->update(['parent_id' => $parentId]);
                $stats['linked']++;
            }

            // Step 3: Optionally delete orphaned genres
            if ($deleteOrphans) {
                $orphanCount = Genre::whereNotIn('name', array_keys($genreDetails))
                    ->whereNull('parent_id')
                    ->delete();
                $this->logger->info("Deleted {$orphanCount} orphaned genres");
            }

            DB::commit();
            $this->logger->info('Genre hierarchy persisted successfully', [
                'created' => $stats['created'],
                'updated' => $stats['updated'],
                'linked' => $stats['linked'],
                'errors' => count($stats['errors']),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            $this->logger->error('Failed to persist genre hierarchy', [
                'error' => $e->getMessage(),
            ]);
            $stats['errors'][] = $e->getMessage();
        }

        return $stats;
    }

    /**
     * Get the complete genre tree.
     *
     * Returns all genres as a hierarchical tree structure using the
     * HasRecursiveRelationships trait functionality from the Genre model.
     *
     * @return array Nested array of genres with their descendants
     */
    public function getGenreTree(): array
    {
        try {
            // Get root genres (those without parents)
            $rootGenres = Genre::query()
                ->whereNull('parent_id')
                ->get();

            // Build the tree using the model's tree relationship
            $tree = $rootGenres->map(function (Genre $genre) {
                return $this->buildGenreTreeNode($genre);
            })->toArray();

            $this->logger->info('Retrieved genre tree', ['count' => count($tree)]);

            return $tree;

        } catch (\Exception $e) {
            $this->logger->error('Failed to retrieve genre tree', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Build a tree node for a genre including its descendants.
     *
     * @param Genre $genre The genre to build the node for
     * @return array Array representation of the genre with its children
     */
    private function buildGenreTreeNode(Genre $genre): array
    {
        $node = [
            'id' => $genre->id,
            'name' => $genre->name,
            'slug' => $genre->slug,
            'mbid' => $genre->mbid,
            'parent_id' => $genre->parent_id,
            'children' => [],
        ];

        // Load children using the children relationship
        if (method_exists($genre, 'children')) {
            $children = $genre->children()->get();

            $node['children'] = $children->map(function (Genre $child) {
                return $this->buildGenreTreeNode($child);
            })->toArray();
        }

        return $node;
    }

    /**
     * Get all descendants of a specific genre.
     *
     * Retrieves all child genres recursively for the given genre slug.
     *
     * @param string $genreSlug The slug of the genre to find descendants for
     * @return Collection Collection of Genre models representing all descendants
     */
    public function getGenreDescendants(string $genreSlug): Collection
    {
        try {
            $genre = Genre::query()
                ->where('slug', $genreSlug)
                ->first();

            if (!$genre) {
                $this->logger->warning("Genre not found: {$genreSlug}");
                return collect();
            }

            if (method_exists($genre, 'descendants')) {
                $descendants = $genre->descendants;
                $this->logger->info("Retrieved descendants for genre: {$genreSlug}", [
                    'count' => $descendants->count(),
                ]);
                return $descendants;
            }

            // Fallback if trait is not available
            return collect();

        } catch (\Exception $e) {
            $this->logger->error('Failed to retrieve genre descendants', [
                'genre_slug' => $genreSlug,
                'error' => $e->getMessage(),
            ]);
            return collect();
        }
    }

    /**
     * Get all ancestors of a specific genre.
     *
     * Retrieves all parent genres recursively up to the root for the given genre slug.
     *
     * @param string $genreSlug The slug of the genre to find ancestors for
     * @return Collection Collection of Genre models representing all ancestors, ordered by depth
     */
    public function getGenreAncestors(string $genreSlug): Collection
    {
        try {
            $genre = Genre::query()
                ->where('slug', $genreSlug)
                ->first();

            if (!$genre) {
                $this->logger->warning("Genre not found: {$genreSlug}");
                return collect();
            }

            if (method_exists($genre, 'ancestors')) {
                $ancestors = $genre->ancestors;
                $this->logger->info("Retrieved ancestors for genre: {$genreSlug}", [
                    'count' => $ancestors->count(),
                ]);
                return $ancestors;
            }

            // Fallback: manually traverse up the tree
            $ancestors = collect();
            $currentGenre = $genre;

            while ($currentGenre->parent_id) {
                $parent = Genre::find($currentGenre->parent_id);
                if (!$parent) {
                    break;
                }
                $ancestors->push($parent);
                $currentGenre = $parent;
            }

            $this->logger->info("Retrieved ancestors for genre: {$genreSlug}", [
                'count' => $ancestors->count(),
            ]);

            return $ancestors;

        } catch (\Exception $e) {
            $this->logger->error('Failed to retrieve genre ancestors', [
                'genre_slug' => $genreSlug,
                'error' => $e->getMessage(),
            ]);
            return collect();
        }
    }
}
