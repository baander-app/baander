<?php

namespace Tests\Unit\Services;

use App\Models\User;
use App\Modules\Metadata\GenreHierarchyService;
use Tests\Helpers\WithMockGenreServices;
use PHPUnit\Framework\Attributes\Test;

class GenreHierarchyServiceTest extends ServiceTestCase
{
    use WithMockGenreServices;

    protected function setUp(): void
    {
        parent::setUp();
        User::factory()->create();
    }

    #[Test]
    public function it_builds_genre_hierarchy(): void
    {
        // APPROACH 1: Use pre-defined fixture sets (most simple)
        $service = $this->mockGenreService()
            ->useFixtureSets(['rock-family', 'electronic-family'])
            ->build();

        $genres = ['rock', 'electronic', 'hard rock', 'techno'];
        $hierarchy = $service->buildGenreHierarchySimple($genres);

        // Assert basic structure
        $this->assertIsArray($hierarchy);
        $this->assertArrayHasKey('root_genres', $hierarchy);
        $this->assertArrayHasKey('subgenres', $hierarchy);
        $this->assertArrayHasKey('relationships', $hierarchy);
        $this->assertArrayHasKey('all_similar_genres', $hierarchy);
        $this->assertArrayHasKey('genre_details', $hierarchy);
        $this->assertArrayHasKey('similarity_matrix', $hierarchy);

        // Assert genre details are populated
        $this->assertArrayHasKey('rock', $hierarchy['genre_details']);
        $this->assertArrayHasKey('electronic', $hierarchy['genre_details']);
        $this->assertArrayHasKey('hard rock', $hierarchy['genre_details']);

        // Assert rock genre has expected structure
        $rockDetails = $hierarchy['genre_details']['rock'];
        $this->assertArrayHasKey('has_similar', $rockDetails);
        $this->assertArrayHasKey('similar_count', $rockDetails);
        $this->assertArrayHasKey('similar_names', $rockDetails);
        $this->assertArrayHasKey('discogs_styles', $rockDetails);
        $this->assertArrayHasKey('popularity', $rockDetails);
        $this->assertArrayHasKey('relationships', $rockDetails);
        $this->assertIsInt($rockDetails['popularity']);
    }

    #[Test]
    public function it_finds_parent_genres(): void
    {
        // APPROACH 2: Compose with dedicated builders (more control)
        $service = $this->mockGenreService()
            ->lastFm(fn($b) => $b
                ->tag('rock', reach: 1_000_000)
                ->tag('hard rock', reach: 500_000)
                ->tag('punk rock', reach: 400_000)
            )
            ->discogs(fn($b) => $b
                ->genres([
                    'rock' => ['hard rock', 'punk', 'classic rock'],
                ])
            )
            ->build();

        $genres = ['rock', 'hard rock', 'punk rock'];
        $hierarchy = $service->buildGenreHierarchySimple($genres);

        // Find relationships where hard rock is a child of rock
        $hardRockParents = collect($hierarchy['relationships'])
            ->filter(fn($rel) => $rel['child'] === 'hard rock')
            ->pluck('parent')
            ->toArray();

        $punkRockParents = collect($hierarchy['relationships'])
            ->filter(fn($rel) => $rel['child'] === 'punk rock')
            ->pluck('parent')
            ->toArray();

        // Assert that rock is identified as a parent of hard rock
        $this->assertContains('rock', $hardRockParents);

        // Assert that rock is identified as a parent of punk rock
        $this->assertContains('rock', $punkRockParents);

        // Assert subgenres list contains the child genres
        $this->assertContains('hard rock', $hierarchy['subgenres']);
        $this->assertContains('punk rock', $hierarchy['subgenres']);
    }

    #[Test]
    public function it_finds_child_genres(): void
    {
        // APPROACH 3: Mix fixture sets with custom overrides
        $service = $this->mockGenreService()
            ->useFixtureSets('electronic-family')
            ->discogs(fn($b) => $b->genre('electronic')->hasStyles(['house', 'techno', 'ambient']))
            ->build();

        $genres = ['electronic', 'house', 'techno', 'ambient'];
        $hierarchy = $service->buildGenreHierarchySimple($genres);

        // Find all children of electronic
        $electronicChildren = collect($hierarchy['relationships'])
            ->filter(fn($rel) => $rel['parent'] === 'electronic')
            ->pluck('child')
            ->toArray();

        // Assert electronic has multiple children
        $this->assertGreaterThanOrEqual(1, count($electronicChildren));
        $this->assertContains('house', $electronicChildren);
        $this->assertContains('techno', $electronicChildren);

        // Check genre details for electronic
        $electronicDetails = $hierarchy['genre_details']['electronic'];
        $this->assertTrue($electronicDetails['has_similar']);
        $this->assertGreaterThan(0, $electronicDetails['similar_count']);
    }

    #[Test]
    public function it_normalizes_genre_names(): void
    {
        // APPROACH 4: Quick & simple with withGenres()
        $service = $this->mockGenreService()
            ->withGenres(['Rock', 'Electronic', 'hip hop', 'R&B'])
            ->build();

        // Test with various capitalizations and spacing
        $genres = ['Rock', '  Electronic  ', 'hip hop', 'R&B'];
        $hierarchy = $service->buildGenreHierarchySimple($genres);

        // Assert genres are normalized and accessible by standardized names
        $this->assertArrayHasKey('Rock', $hierarchy['genre_details']);
        $this->assertArrayHasKey('Electronic', $hierarchy['genre_details']);
        $this->assertArrayHasKey('hip hop', $hierarchy['genre_details']);
        $this->assertArrayHasKey('R&B', $hierarchy['genre_details']);

        // Assert similarity matrix uses normalized keys
        $this->assertArrayHasKey('Rock', $hierarchy['similarity_matrix']);

        // Test similarity calculation with normalized names
        $similarity = $service->getGenreSimilarity($hierarchy, 'Rock', 'Electronic');
        $this->assertGreaterThanOrEqual(0.0, $similarity);
        $this->assertLessThanOrEqual(1.0, $similarity);
    }

    #[Test]
    public function it_handles_empty_genre_list(): void
    {
        $service = $this->mockGenreService()->build();
        $hierarchy = $service->buildGenreHierarchySimple([]);

        // Assert structure is maintained even with empty input
        $this->assertIsArray($hierarchy);
        $this->assertArrayHasKey('root_genres', $hierarchy);
        $this->assertArrayHasKey('subgenres', $hierarchy);
        $this->assertArrayHasKey('relationships', $hierarchy);
        $this->assertArrayHasKey('all_similar_genres', $hierarchy);
        $this->assertArrayHasKey('genre_details', $hierarchy);
        $this->assertArrayHasKey('similarity_matrix', $hierarchy);

        // Assert arrays are empty
        $this->assertEmpty($hierarchy['root_genres']);
        $this->assertEmpty($hierarchy['subgenres']);
        $this->assertEmpty($hierarchy['relationships']);
        $this->assertEmpty($hierarchy['all_similar_genres']);
        $this->assertEmpty($hierarchy['genre_details']);
        $this->assertEmpty($hierarchy['similarity_matrix']);
    }

    #[Test]
    public function it_calculates_genre_similarity(): void
    {
        // APPROACH 5: Override specific values from fixture sets
        $service = $this->mockGenreService()
            ->useFixtureSets('rock-family')
            ->override('lastfm.tag_getInfo_rock.tag.reach', 2_000_000)
            ->build();

        $genres = ['rock', 'hard rock'];
        $hierarchy = $service->buildGenreHierarchySimple($genres);

        // Test self-similarity
        $selfSimilarity = $service->getGenreSimilarity($hierarchy, 'rock', 'rock');
        $this->assertEquals(1.0, $selfSimilarity);

        // Test similarity between related genres
        $rockHardRockSimilarity = $service->getGenreSimilarity($hierarchy, 'rock', 'hard rock');
        $this->assertGreaterThan(0.0, $rockHardRockSimilarity);
        $this->assertLessThanOrEqual(1.0, $rockHardRockSimilarity);
    }

    #[Test]
    public function it_handles_api_failures_gracefully(): void
    {
        // Test failure simulation
        $service = $this->mockGenreService()
            ->withGenres(['rock', 'electronic'])
            ->withFailures()
            ->build();

        $genres = ['rock', 'electronic'];
        $hierarchy = $service->buildGenreHierarchySimple($genres);

        // Service should still return a valid structure even on API failures
        $this->assertIsArray($hierarchy);
        $this->assertArrayHasKey('root_genres', $hierarchy);
        $this->assertArrayHasKey('genre_details', $hierarchy);

        // Genres should still be present in details
        $this->assertArrayHasKey('rock', $hierarchy['genre_details']);
        $this->assertArrayHasKey('electronic', $hierarchy['genre_details']);

        // Popularity should default to 0 when API fails
        $this->assertEquals(0, $hierarchy['genre_details']['rock']['popularity']);
        $this->assertEquals(0, $hierarchy['genre_details']['electronic']['popularity']);
    }

    #[Test]
    public function it_builds_similarity_matrix(): void
    {
        // Multiple fixture sets combined
        $service = $this->mockGenreService()
            ->useFixtureSets(['rock-family', 'electronic-family'])
            ->build();

        $genres = ['rock', 'electronic', 'hard rock', 'techno'];
        $hierarchy = $service->buildGenreHierarchySimple($genres);

        // Assert similarity matrix structure
        $this->assertArrayHasKey('similarity_matrix', $hierarchy);
        $matrix = $hierarchy['similarity_matrix'];

        // Assert matrix contains all genres
        $this->assertArrayHasKey('rock', $matrix);
        $this->assertArrayHasKey('electronic', $matrix);
        $this->assertArrayHasKey('hard rock', $matrix);

        // Assert self-similarity is 1.0
        $this->assertEquals(1.0, $matrix['rock']['rock']);
        $this->assertEquals(1.0, $matrix['electronic']['electronic']);

        // Assert similarities are in valid range
        $this->assertGreaterThanOrEqual(0.0, $matrix['rock']['electronic']);
        $this->assertLessThanOrEqual(1.0, $matrix['rock']['electronic']);
    }

    #[Test]
    public function it_limits_relationships_to_top_five(): void
    {
        // Builder with custom configuration
        $service = $this->mockGenreService()
            ->lastFm(fn($b) => $b
                ->withDefaultReach(500_000)
                ->tags(['rock', 'rock and roll', 'rockabilly', 'classic rock', 'indie rock', 'punk rock', 'alternative rock'])
            )
            ->discogs(fn($b) => $b->genres(['rock' => ['classic rock', 'punk rock', 'alternative']]))
            ->build();

        $genres = ['rock', 'rock and roll', 'rockabilly', 'classic rock', 'indie rock', 'punk rock', 'alternative rock'];
        $hierarchy = $service->buildGenreHierarchySimple($genres);

        // Check that rock has at most 5 relationships
        $rockRelationships = $hierarchy['genre_details']['rock']['relationships'];
        $this->assertLessThanOrEqual(5, count($rockRelationships));

        // Assert relationships are sorted by similarity (descending)
        $similarities = array_column($rockRelationships, 'match');
        $sortedSimilarities = $similarities;
        rsort($sortedSimilarities);
        $this->assertEquals($sortedSimilarities, $similarities);
    }

    #[Test]
    public function it_handles_manual_genre_relationships(): void
    {
        // Simple fixture set approach
        $service = $this->mockGenreService()
            ->useFixtureSets(['rock-family', 'electronic-family'])
            ->build();

        $genres = ['rock', 'alternative rock', 'hard rock', 'electronic', 'techno'];
        $hierarchy = $service->buildGenreHierarchySimple($genres);

        // Check for manual relationships for rock
        $rockRelationships = collect($hierarchy['genre_details']['rock']['relationships']);
        $rockRelated = $rockRelationships->pluck('name')->toArray();

        // Alternative rock and hard rock should be related to rock via manual rules
        $this->assertContains('alternative rock', $rockRelated);
        $this->assertContains('hard rock', $rockRelated);

        // Check for electronic relationships
        $electronicRelationships = collect($hierarchy['genre_details']['electronic']['relationships']);
        $electronicRelated = $electronicRelationships->pluck('name')->toArray();

        // Techno should be related to electronic via manual rules
        $this->assertContains('techno', $electronicRelated);
    }

    #[Test]
    public function it_deduplicates_relationships(): void
    {
        // Custom configuration with duplicate detection
        $service = $this->mockGenreService()
            ->lastFm(fn($b) => $b
                ->tag('rock', reach: 1_000_000)
                ->tag('Rock', reach: 600_000) // Different case
                ->tag('hard rock', reach: 500_000)
            )
            ->discogs(fn($b) => $b->genres(['rock' => ['hard rock']]))
            ->build();

        $genres = ['rock', 'Rock', 'hard rock'];
        $hierarchy = $service->buildGenreHierarchySimple($genres);

        // Get all relationships
        $allRelationships = $hierarchy['relationships'];

        // Check that there are no duplicate parent-child pairs
        $uniquePairs = [];
        foreach ($allRelationships as $rel) {
            $key = $rel['parent'] . '|' . $rel['child'];
            $this->assertNotContains($key, $uniquePairs, "Duplicate relationship found: {$key}");
            $uniquePairs[] = $key;
        }
    }
}
