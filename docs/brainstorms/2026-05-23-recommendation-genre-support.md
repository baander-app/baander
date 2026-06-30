---
date: 2026-05-23
topic: recommendation-genre-support
---

# Recommendation Genre Support

## Summary

Enable the genre-based recommendation strategy by adding the missing Song-to-Genre ORM association, updating the recommendation query to fetch genres, and implementing genre data retrieval in the recommendation handler.

---

## Problem Frame

The recommendation feature has four strategies (collaborative filtering, content similarity, genre similarity, database relations), but genre similarity is non-functional. The `GenreSimilarityCalculator` algorithm works correctly, but it receives empty genre arrays because:

1. `SongEntity` lacks the inverse `genres` association to `GenreSongEntity`
2. `findAllForRecommendations()` attempts to JOIN `s.genres` which doesn't exist
3. `getGenreNames()` in the handler returns an empty array with a TODO comment

The infrastructure exists (Genre aggregate, GenreSongEntity join table, similarity calculator) — the wiring between Song and Genre is incomplete.

---

## Requirements

**ORM association**
- R1. `SongEntity` must have a `genres` association (OneToMany to GenreSongEntity)
- R2. The association must map the correct join column and cascade rules

**Query updates**
- R3. `findAllForRecommendations()` must LEFT JOIN genres to avoid N+1 queries
- R4. The query must work correctly after the genres association is added

**Handler implementation**
- R5. `getGenreNames()` must return actual genre names from the song's genre associations
- R6. Genre names must be extracted from the associated Genre entities

**Data integrity**
- R7. The fix must not break existing recommendation generation (collaborative, content, database strategies)
- R8. Empty genre sets should produce zero similarity score (existing calculator behavior)

---

## Acceptance Examples

- AE1. **Covers R1, R2, R3.** Given songs with associated genres, when `findAllForRecommendations()` is called, genres are fetched in the same query without N+1 issues.
- AE2. **Covers R5, R6.** Given a song with genres "Rock" and "Alternative", when `getGenreNames()` is called, it returns `["Rock", "Alternative"]`.
- AE3. **Covers R7, R8.** Given a song with no genres, when recommendations are generated, the genre similarity strategy contributes zero recommendations.

---

## Success Criteria

- Genre-based song recommendations are generated with non-zero similarity scores when songs share genres
- The `/api/admin/recommendations/generate` endpoint completes without errors
- All four recommendation strategies (collaborative, content, genre, database) produce results

---

## Scope Boundaries

- Adding genre UI or admin endpoints for managing song-genre relationships
- Changing the GenreSimilarityCalculator algorithm (it already works correctly)
- Adding new recommendation strategies beyond the existing four
- Optimizing recommendation generation performance beyond fixing the N+1 query

---

## Key Decisions

- **Full ORM association over direct query**: Aligns with existing DDD patterns in the Catalog context, leverages Doctrine's cascade and lazy loading
- **Keep existing GenreSimilarityCalculator**: The algorithm correctly computes Jaccard similarity; only the data source was broken

---

## Dependencies / Assumptions

- GenreSongEntity join table and Genre aggregate already exist and are functional
- GenreSimilarityCalculator's Jaccard similarity algorithm is acceptable for the use case
- Loading all songs with genres in one query is acceptable for the expected catalog size

---

## Outstanding Questions

### Deferred to Planning

- [Needs research] Verify the exact Doctrine association configuration (mappedBy, inversedBy) needed for the Song-GenreSong relationship
- [Needs research] Confirm whether genre hierarchies (parent genres) should be used in similarity calculations via the weightedSimilarity() method
