---
date: 2026-05-23
sequence: 001
type: feat
status: completed
origin: docs/brainstorms/2026-05-23-recommendation-genre-support.md
---

# feat: Enable Genre-Based Recommendations

## Summary

Add the missing Song-to-Genre ORM association, update the recommendation query to fetch genres, and implement genre data retrieval in the recommendation handler to enable the genre similarity strategy.

## Problem Frame

The recommendation feature has four strategies (collaborative filtering, content similarity, genre similarity, database relations), but genre similarity is non-functional. The GenreSimilarityCalculator algorithm works correctly, but it receives empty genre arrays because:

1. SongEntity lacks the inverse genres association to GenreSongEntity
2. findAllForRecommendations() attempts to JOIN s.genres which doesn't exist
3. getGenreNames() in the handler returns an empty array with a TODO comment

The infrastructure exists (Genre aggregate, GenreSongEntity join table, similarity calculator) — the wiring between Song and Genre is incomplete.

## Requirements

From the origin document:

- R1. SongEntity must have a genres association (OneToMany to GenreSongEntity)
- R2. The association must map the correct join column and cascade rules
- R3. findAllForRecommendations() must LEFT JOIN genres to avoid N+1 queries
- R4. The query must work correctly after the genres association is added
- R5. getGenreNames() must return actual genre names from the song's genre associations
- R6. Genre names must be extracted from the associated Genre entities
- R7. The fix must not break existing recommendation generation (collaborative, content, database strategies)
- R8. Empty genre sets should produce zero similarity score (existing calculator behavior)

## Key Technical Decisions

- **OneToMany association pattern**: Add OneToMany on SongEntity pointing to GenreSongEntity with mappedBy='song'. This is the standard Doctrine inverse-side pattern for join tables.
- **Fetch genres in same query**: LEFT JOIN genres in findAllForRecommendations() to avoid N+1. Genres are lazy-loaded by default; explicitly fetching ensures they're available.
- **Simple Jaccard similarity**: Use jaccardSimilarity() rather than weightedSimilarity() for the initial implementation. Genre hierarchies (parent/child) exist in the data model but are deferred for simplicity.

## Implementation Units

### U1. Add genres association to SongEntity

**Goal**: Enable Doctrine to load GenreSongEntity associations from SongEntity.

**Requirements**: R1, R2

**Dependencies**: None

**Files**:
- `src/Catalog/Infrastructure/Doctrine/Entity/SongEntity.php`

**Approach**:
- Add a OneToMany association property pointing to GenreSongEntity
- Use mappedBy='song' to point to the song property on GenreSongEntity
- Use orphanRemoval=true or cascade=['remove'] for cleanup
- Add getGenres() getter method returning array of GenreSongEntity

**Technical design**:
```php
#[ORM\OneToMany(mappedBy: 'song', targetEntity: GenreSongEntity::class)]
private Collection $genres;

// In constructor:
$this->genres = new ArrayCollection();

// Getter:
public function getGenres(): Collection
{
    return $this->genres;
}
```

**Patterns to follow**: Standard Doctrine OneToMany inverse-side pattern

**Test scenarios**:
- Given a song with associated genres, when getGenres() is called, it returns the GenreSongEntity collection
- Given a song with no genres, when getGenres() is called, it returns an empty collection
- Given a persisted song with genres, when the entity is reconstituted from database, genres are correctly loaded

**Verification**: Song entity loads without errors; getGenres() returns expected GenreSongEntity instances

---

### U2. Update findAllForRecommendations() to fetch genres

**Goal**: Load genres in the same query to avoid N+1 problems when generating recommendations.

**Requirements**: R3, R4

**Dependencies**: U1

**Files**:
- `src/Catalog/Infrastructure/Doctrine/Repository/SongRepository.php`
- `tests/Functional/Catalog/Infrastructure/Repository/SongRepositoryTest.php`

**Approach**:
- Add leftJoin('s.genres', 'gs') to the QueryBuilder
- This makes genres available without additional queries
- The existing album join pattern should be mirrored

**Technical design**:
```php
public function findAllForRecommendations(): array
{
    $entities = $this->entityManager
        ->getRepository(SongEntity::class)
        ->createQueryBuilder('s')
        ->leftJoin('s.album', 'a')
        ->leftJoin('s.genres', 'gs')
        ->getQuery()
        ->getResult();

    return array_map(fn (SongEntity $entity) => $this->toDomain($entity), $entities);
}
```

**Patterns to follow**: Existing leftJoin('s.album', 'a') pattern in the same method

**Test scenarios**:
- Given songs with associated genres in database, when findAllForRecommendations() is called, all songs are returned
- Given the query execution, when Doctrine logs queries, no N+1 additional queries are fired when accessing genres
- Given songs with no genres, when findAllForRecommendations() is called, songs are still returned (genres is empty collection)

**Verification**: Query executes without errors; genres are accessible on returned entities; no N+1 queries

---

### U3. Implement getGenreNames() in GenerateRecommendationsHandler

**Goal**: Extract actual genre names from song associations for similarity calculation.

**Requirements**: R5, R6

**Dependencies**: U1, U2

**Files**:
- `src/Recommendation/Application/CommandHandler/GenerateRecommendationsHandler.php`
- `tests/Unit/Recommendation/Application/CommandHandler/GenerateRecommendationsHandlerTest.php`

**Approach**:
- Modify getGenreNames() to accept Song domain model
- Extract genre names from the Song domain model's genre associations
- Note: This requires Song domain model to carry genre data, or passing the entity through a port
- Two options: (a) add genre data to Song domain model, or (b) create a port method to fetch genre names
- Recommendation: Create a catalog port method getSongGenreNames(Uuid $songId): array to avoid contaminating the domain model with infrastructure concerns

**Technical design**:
Add bulk query method to SongRepositoryInterface:
```php
// In SongRepositoryInterface
/**
 * @param Uuid[] $songIds
 * @return array<string, string[]> songId => genreNames[]
 */
public function getGenreNamesForSongs(array $songIds): array;

// Implementation in SongRepository
public function getGenreNamesForSongs(array $songIds): array
{
    $qb = $this->entityManager->createQueryBuilder();
    $qb->select('IDENTITY(gs.song) as songId', 'g.name')
       ->from(GenreSongEntity::class, 'gs')
       ->join('gs.genre', 'g')
       ->where($qb->expr()->in('gs.song', ':songIds'))
       ->setParameter('songIds', $songIds);

    $rows = $qb->getQuery()->getResult();

    $map = [];
    foreach ($rows as ['songId' => $songId, 'name' => $name]) {
        $map[$songId][] = $name;
    }

    return $map;
}

// In GenerateRecommendationsHandler (before genre loop)
$genreMap = $this->songRepository->getGenreNamesForSongs(
    array_map(fn($s) => $s->getId(), $songs)
);

private function getGenreNames(\App\Catalog\Domain\Model\Song $song, array $genreMap): array
{
    return $genreMap[$song->getId()->toString()] ?? [];
}
```

Option B (domain model approach):
- Add genreNames property to Song domain model state
- Populate it during repository reconstitution

**Decision**: Repository bulk query approach (A) is preferred — avoids N+1 queries, keeps domain model pure, and follows existing `getArtistNamesForSongs()` pattern in Catalog.

**Patterns to follow**: Existing port interfaces in Application/Port/

**Test scenarios**:
- Covers AE2. Given a song with genres "Rock" and "Alternative", when getGenreNames() is called, it returns ["Rock", "Alternative"]
- Given a song with no genres, when getGenreNames() is called, it returns an empty array
- Given the genre similarity calculation, when songs share genres, the similarity score is greater than zero
- Given songs with no overlapping genres, when similarity is calculated, the score is zero

**Verification**: Genre similarity recommendations are generated with non-zero scores for songs sharing genres

---

### U4. Verify all recommendation strategies still work

**Goal**: Ensure genre changes don't break collaborative, content, or database strategies.

**Requirements**: R7, R8

**Dependencies**: U1, U2, U3

**Files**:
- `tests/Functional/Recommendation/Application/CommandHandler/GenerateRecommendationsHandlerTest.php`

**Approach**:
- Run the full recommendation generation end-to-end
- Verify all four strategies produce recommendations
- Genre strategy should produce zero results for songs with no genres (respecting R8)

**Test scenarios**:
- Covers AE3. Given a song with no genres, when recommendations are generated, the genre similarity strategy contributes zero recommendations
- Given a catalog with songs having genres, when full recommendations are generated, all four strategies (collaborative, content, genre, database) produce results
- Given the API endpoint /api/admin/recommendations/generate, when called, it returns 200 OK with recommendation counts per strategy

**Verification**: All strategies execute; genre similarity works for songs with genres; empty genres produce zero similarity

---

## Success Criteria

- Genre-based song recommendations are generated with non-zero similarity scores when songs share genres
- The /api/admin/recommendations/generate endpoint completes without errors
- All four recommendation strategies (collaborative, content, genre, database) produce results
- No N+1 query problems when loading songs with genres

## Scope Boundaries

### Deferred for later
- Using weightedSimilarity() for genre hierarchy (parent/child relationships)
- Genre admin UI or endpoints for managing song-genre relationships
- Performance optimization for very large catalogs (e.g., batch processing, pagination)
- Caching genre similarity scores

### Outside this product's identity
- Changing the GenreSimilarityCalculator algorithm
- Adding new recommendation strategies beyond the existing four
- Modifying the collaborative, content, or database strategies

## System-Wide Impact

This change affects:
- Catalog bounded context: SongEntity gains a new association
- Recommendation bounded context: Handler uses actual genre data
- Database: No schema changes required (genre_song table exists)

## Dependencies / Prerequisites

- GenreSongEntity and GenreEntity must exist (confirmed: already present)
- GenreSimilarityCalculator must be functional (confirmed: algorithm works)

## Risk Analysis & Mitigation

| Risk | Impact | Mitigation |
|------|--------|------------|
| N+1 query performance when loading all songs with genres | Medium | LEFT JOIN in same query; verify with Doctrine query logging |
| Genre data may be sparse in catalog, producing few recommendations | Low | Document expectation; genre strategy is one of four |
| OneToMany association pattern is new to codebase | Low | Follow standard Doctrine patterns; add tests |

## Outstanding Questions

### Deferred to Implementation
- [Needs research] Exact Doctrine configuration (fetch mode, cascade options) - settle during implementation based on runtime behavior
- [Needs research] Whether Song domain model should carry genre names or use port approach - decided: port approach preferred
