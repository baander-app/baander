<?php

namespace Tests\Unit\Services;

use App\Models\Genre;
use App\Modules\Metadata\GenrePersister;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

class GenrePersisterTest extends ServiceTestCase
{
    private GenrePersister $persister;

    protected function setUp(): void
    {
        parent::setUp();

        // Http::fake() to prevent any external API calls during tests
        Http::fake();

        $this->persister = app(GenrePersister::class);
    }

    #[Test]
    public function it_persists_genre_hierarchy(): void
    {
        $hierarchyData = [
            [
                'name' => 'Rock',
                'mbid' => '1f4df26d-71d4-4494-b839-5df5c4bbfcfa',
                'children' => [
                    [
                        'name' => 'Hard Rock',
                        'mbid' => 'a3b8c9d2-1e4f-4a5b-8c3d-9e6f1a2b3c4d',
                    ],
                ],
            ],
        ];

        $stats = $this->persister->persistHierarchy($hierarchyData);

        // Assert statistics
        $this->assertEquals(2, $stats['created']);
        $this->assertEquals(0, $stats['updated']);
        $this->assertEquals(0, $stats['linked']);
        $this->assertEmpty($stats['errors']);

        // Assert genres were created
        $this->assertDatabaseHas('genres', [
            'name' => 'Rock',
            'slug' => 'rock',
            'mbid' => '1f4df26d-71d4-4494-b839-5df5c4bbfcfa',
            'parent_id' => null,
        ]);

        $this->assertDatabaseHas('genres', [
            'name' => 'Hard Rock',
            'slug' => 'hard-rock',
            'mbid' => 'a3b8c9d2-1e4f-4a5b-8c3d-9e6f1a2b3c4d',
        ]);

        // Assert parent-child relationship
        $hardRock = Genre::where('slug', 'hard-rock')->first();
        $rock = Genre::where('slug', 'rock')->first();

        $this->assertEquals($rock->id, $hardRock->parent_id);
    }

    #[Test]
    public function it_creates_parent_child_relationships(): void
    {
        $hierarchyData = [
            [
                'name' => 'Electronic',
                'mbid' => '550e8400-e29b-41d4-a716-446655440000',
                'children' => [
                    [
                        'name' => 'House',
                        'mbid' => '6ba7b810-9dad-11d1-80b4-00c04fd430c8',
                        'children' => [
                            [
                                'name' => 'Deep House',
                                'mbid' => '6ba7b811-9dad-11d1-80b4-00c04fd430c8',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->persister->persistHierarchy($hierarchyData);

        $electronic = Genre::where('slug', 'electronic')->first();
        $house = Genre::where('slug', 'house')->first();
        $deepHouse = Genre::where('slug', 'deep-house')->first();

        // Assert parent IDs are set correctly
        $this->assertNull($electronic->parent_id);
        $this->assertEquals($electronic->id, $house->parent_id);
        $this->assertEquals($house->id, $deepHouse->parent_id);

        // Verify hierarchy depth
        $this->assertEquals(0, $electronic->ancestors()->count());
        $this->assertEquals(1, $house->ancestors()->count());
        $this->assertEquals(2, $deepHouse->ancestors()->count());
    }

    #[Test]
    public function it_is_idempotent(): void
    {
        $hierarchyData = [
            [
                'name' => 'Jazz',
                'mbid' => '6ba7b812-9dad-11d1-80b4-00c04fd430c8',
                'children' => [
                    [
                        'name' => 'Bebop',
                        'mbid' => '6ba7b813-9dad-11d1-80b4-00c04fd430c8',
                    ],
                ],
            ],
        ];

        // Run persistHierarchy twice
        $stats1 = $this->persister->persistHierarchy($hierarchyData);
        $stats2 = $this->persister->persistHierarchy($hierarchyData);

        // First run should create 2 genres
        $this->assertEquals(2, $stats1['created']);

        // Second run should not create any new genres (with update_existing = true by default)
        $this->assertEquals(0, $stats2['created']);
        $this->assertEquals(2, $stats2['updated']);

        // Verify we still only have 2 genres in the database
        $this->assertEquals(2, Genre::count());

        // Verify no duplicates
        $jazzCount = Genre::where('slug', 'jazz')->count();
        $bebopCount = Genre::where('slug', 'bebop')->count();

        $this->assertEquals(1, $jazzCount);
        $this->assertEquals(1, $bebopCount);
    }

    #[Test]
    public function it_returns_correct_statistics(): void
    {
        // Pre-create an existing genre
        Genre::factory()->create([
            'name' => 'Rock',
            'slug' => 'rock',
            'mbid' => '6ba7b817-9dad-11d1-80b4-00c04fd430c8',
        ]);

        $hierarchyData = [
            [
                'name' => 'Rock',
                'mbid' => '6ba7b837-9dad-11d1-80b4-00c04fd430c8',
                'children' => [
                    [
                        'name' => 'Hard Rock',
                        'mbid' => '6ba7b815-9dad-11d1-80b4-00c04fd430c8',
                    ],
                    [
                        'name' => 'Punk Rock',
                        'mbid' => '6ba7b816-9dad-11d1-80b4-00c04fd430c8',
                    ],
                ],
            ],
            [
                'name' => 'Jazz',
                'mbid' => '6ba7b812-9dad-11d1-80b4-00c04fd430c8',
            ],
        ];

        $stats = $this->persister->persistHierarchy($hierarchyData);

        // 1 updated (Rock) + 3 created (Hard Rock, Punk Rock, Jazz)
        // Note: linked count is 0 because parent_id is updated as part of main update when update_existing=true
        $this->assertEquals(3, $stats['created']);
        $this->assertEquals(1, $stats['updated']);
        $this->assertEquals(0, $stats['linked']);
        $this->assertEmpty($stats['errors']);

        // Verify total count
        $this->assertEquals(4, Genre::count());
    }

    #[Test]
    public function it_updates_existing_genres_when_enabled(): void
    {
        // Create an existing genre with old data
        Genre::factory()->create([
            'name' => 'Rock',
            'slug' => 'rock',
            'mbid' => '6ba7b818-9dad-11d1-80b4-00c04fd430c8',
        ]);

        $hierarchyData = [
            [
                'name' => 'Rock',
                'mbid' => '6ba7b819-9dad-11d1-80b4-00c04fd430c8',
                'children' => [
                    [
                        'name' => 'Classic Rock',
                        'mbid' => '6ba7b81a-9dad-11d1-80b4-00c04fd430c8',
                    ],
                ],
            ],
        ];

        // Default behavior is update_existing = true
        $stats = $this->persister->persistHierarchy($hierarchyData);

        $this->assertEquals(1, $stats['created']); // Classic Rock
        $this->assertEquals(1, $stats['updated']); // Rock

        // Verify Rock was updated with new mbid
        $rock = Genre::where('slug', 'rock')->first();
        $this->assertEquals('6ba7b819-9dad-11d1-80b4-00c04fd430c8', $rock->mbid);
    }

    #[Test]
    public function it_does_not_update_existing_genres_when_disabled(): void
    {
        // Create an existing genre with old data
        Genre::factory()->create([
            'name' => 'Rock',
            'slug' => 'rock',
            'mbid' => '6ba7b818-9dad-11d1-80b4-00c04fd430c8',
        ]);

        $hierarchyData = [
            [
                'name' => 'Rock',
                'mbid' => '6ba7b819-9dad-11d1-80b4-00c04fd430c8',
                'children' => [
                    [
                        'name' => 'Classic Rock',
                        'mbid' => '6ba7b81a-9dad-11d1-80b4-00c04fd430c8',
                    ],
                ],
            ],
        ];

        // Disable update_existing
        $stats = $this->persister->persistHierarchy($hierarchyData, [
            'update_existing' => false,
        ]);

        $this->assertEquals(1, $stats['created']); // Classic Rock
        $this->assertEquals(0, $stats['updated']); // Rock should not be updated

        // Verify Rock mbid was NOT changed
        $rock = Genre::where('slug', 'rock')->first();
        $this->assertEquals('6ba7b818-9dad-11d1-80b4-00c04fd430c8', $rock->mbid);

        // Verify parent-child relationship was still established
        $classicRock = Genre::where('slug', 'classic-rock')->first();
        $this->assertEquals($rock->id, $classicRock->parent_id);
    }

    #[Test]
    public function it_deletes_orphaned_genres_when_requested(): void
    {
        // Create some existing genres
        Genre::factory()->create([
            'name' => 'Orphan Genre',
            'slug' => 'orphan-genre',
            'mbid' => '6ba7b81b-9dad-11d1-80b4-00c04fd430c8',
        ]);

        Genre::factory()->create([
            'name' => 'Another Orphan',
            'slug' => 'another-orphan',
            'mbid' => '6ba7b81c-9dad-11d1-80b4-00c04fd430c8',
        ]);

        $hierarchyData = [
            [
                'name' => 'Rock',
                'mbid' => '6ba7b836-9dad-11d1-80b4-00c04fd430c8',
            ],
        ];

        // Persist with delete_orphans option
        $stats = $this->persister->persistHierarchy($hierarchyData, [
            'delete_orphans' => true,
        ]);

        // Verify orphans were deleted
        $this->assertDatabaseMissing('genres', [
            'slug' => 'orphan-genre',
        ]);

        $this->assertDatabaseMissing('genres', [
            'slug' => 'another-orphan',
        ]);

        // Only Rock should remain
        $this->assertEquals(1, Genre::count());
    }

    #[Test]
    public function it_does_not_delete_orphans_by_default(): void
    {
        // Create some existing genres
        Genre::factory()->create([
            'name' => 'Orphan Genre',
            'slug' => 'orphan-genre',
            'mbid' => '6ba7b81b-9dad-11d1-80b4-00c04fd430c8',
        ]);

        $hierarchyData = [
            [
                'name' => 'Rock',
                'mbid' => '6ba7b836-9dad-11d1-80b4-00c04fd430c8',
            ],
        ];

        // Persist without delete_orphans option
        $this->persister->persistHierarchy($hierarchyData);

        // Verify orphans are still present
        $this->assertDatabaseHas('genres', [
            'slug' => 'orphan-genre',
        ]);

        // Both genres should exist
        $this->assertEquals(2, Genre::count());
    }

    #[Test]
    public function it_handles_missing_genre_in_relationships(): void
    {
        // This test verifies the service handles relationships gracefully
        // when a child genre reference exists but the parent is missing
        $hierarchyData = [
            [
                'name' => 'Electronic',
                'mbid' => '550e8400-e29b-41d4-a716-446655440000',
                'children' => [
                    [
                        'name' => 'Techno',
                        'mbid' => '6ba7b81d-9dad-11d1-80b4-00c04fd430c8',
                    ],
                    // Invalid relationship with missing name
                    [
                        'mbid' => '6ba7b81e-9dad-11d1-80b4-00c04fd430c8',
                    ],
                ],
            ],
        ];

        $stats = $this->persister->persistHierarchy($hierarchyData);

        // Should handle the error gracefully
        $this->assertNotEmpty($stats['errors']);
        $this->assertEquals(2, $stats['created']); // Electronic and Techno

        // Verify valid genres were created
        $this->assertDatabaseHas('genres', [
            'slug' => 'electronic',
        ]);

        $this->assertDatabaseHas('genres', [
            'slug' => 'techno',
        ]);
    }

    #[Test]
    public function it_rolls_back_on_error(): void
    {
        // Start a transaction to simulate a failure
        DB::beginTransaction();

        try {
            // Create initial genre
            Genre::factory()->create([
                'name' => 'Rock',
                'slug' => 'rock',
                'mbid' => '6ba7b836-9dad-11d1-80b4-00c04fd430c8',
            ]);

            $initialCount = Genre::count();

            // Attempt to persist invalid data (missing required name field)
            $hierarchyData = [
                [
                    'mbid' => 'invalid-no-name',
                    'children' => [
                        [
                            'name' => 'Should Not Be Created',
                            'mbid' => '6ba7b81f-9dad-11d1-80b4-00c04fd430c8',
                        ],
                    ],
                ],
            ];

            $stats = $this->persister->persistHierarchy($hierarchyData);

            // Should have errors
            $this->assertNotEmpty($stats['errors']);

            // Count should remain the same (transaction rollback)
            $this->assertEquals($initialCount, Genre::count());

            // Verify child was not created despite being valid
            $this->assertDatabaseMissing('genres', [
                'slug' => 'should-not-be-created',
            ]);

        } finally {
            DB::rollBack();
        }
    }

    #[Test]
    public function it_saves_musicbrainz_mbid(): void
    {
        $hierarchyData = [
            [
                'name' => 'Rock',
                'mbid' => '1f4df26d-71d4-4494-b839-5df5c4bbfcfa',
            ],
            [
                'name' => 'Jazz',
                'mbid' => '6ba7b820-9dad-11d1-80b4-00c04fd430c8',
            ],
        ];

        $this->persister->persistHierarchy($hierarchyData);

        $this->assertDatabaseHas('genres', [
            'slug' => 'rock',
            'mbid' => '1f4df26d-71d4-4494-b839-5df5c4bbfcfa',
        ]);

        $this->assertDatabaseHas('genres', [
            'slug' => 'jazz',
            'mbid' => '6ba7b820-9dad-11d1-80b4-00c04fd430c8',
        ]);

        // Verify mbid attribute is accessible
        $rock = Genre::where('slug', 'rock')->first();
        $this->assertEquals('1f4df26d-71d4-4494-b839-5df5c4bbfcfa', $rock->mbid);
        $this->assertEquals('https://musicbrainz.org/genre/1f4df26d-71d4-4494-b839-5df5c4bbfcfa', $rock->musicBrainzUrl);
    }

    #[Test]
    public function it_handles_null_mbid_gracefully(): void
    {
        $hierarchyData = [
            [
                'name' => 'Custom Genre',
                // No mbid provided
            ],
            [
                'name' => 'Another Custom Genre',
                'mbid' => null, // Explicit null
            ],
        ];

        $stats = $this->persister->persistHierarchy($hierarchyData);

        // Should succeed without errors
        $this->assertEmpty($stats['errors']);
        $this->assertEquals(2, $stats['created']);

        // Verify genres were created with null mbid
        $this->assertDatabaseHas('genres', [
            'slug' => 'custom-genre',
            'mbid' => null,
        ]);

        $this->assertDatabaseHas('genres', [
            'slug' => 'another-custom-genre',
            'mbid' => null,
        ]);

        // Verify musicBrainzUrl returns null
        $genre = Genre::where('slug', 'custom-genre')->first();
        $this->assertNull($genre->mbid);
        $this->assertNull($genre->musicBrainzUrl);
    }

    #[Test]
    public function it_handles_complex_multi_level_hierarchy(): void
    {
        $hierarchyData = [
            [
                'name' => 'Rock',
                'mbid' => '6ba7b836-9dad-11d1-80b4-00c04fd430c8',
                'children' => [
                    [
                        'name' => 'Alternative Rock',
                        'mbid' => '6ba7b821-9dad-11d1-80b4-00c04fd430c8',
                        'children' => [
                            [
                                'name' => 'Indie Rock',
                                'mbid' => '6ba7b822-9dad-11d1-80b4-00c04fd430c8',
                            ],
                            [
                                'name' => 'Grunge',
                                'mbid' => '6ba7b823-9dad-11d1-80b4-00c04fd430c8',
                            ],
                        ],
                    ],
                    [
                        'name' => 'Heavy Metal',
                        'mbid' => '6ba7b824-9dad-11d1-80b4-00c04fd430c8',
                        'children' => [
                            [
                                'name' => 'Thrash Metal',
                                'mbid' => '6ba7b825-9dad-11d1-80b4-00c04fd430c8',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $stats = $this->persister->persistHierarchy($hierarchyData);

        // All 6 genres should be created
        $this->assertEquals(6, $stats['created']);
        $this->assertEmpty($stats['errors']);

        // Verify hierarchy structure
        $rock = Genre::where('slug', 'rock')->first();
        $altRock = Genre::where('slug', 'alternative-rock')->first();
        $indieRock = Genre::where('slug', 'indie-rock')->first();
        $grunge = Genre::where('slug', 'grunge')->first();
        $heavyMetal = Genre::where('slug', 'heavy-metal')->first();
        $thrashMetal = Genre::where('slug', 'thrash-metal')->first();

        // Verify parent relationships
        $this->assertNull($rock->parent_id);
        $this->assertEquals($rock->id, $altRock->parent_id);
        $this->assertEquals($altRock->id, $indieRock->parent_id);
        $this->assertEquals($altRock->id, $grunge->parent_id);
        $this->assertEquals($rock->id, $heavyMetal->parent_id);
        $this->assertEquals($heavyMetal->id, $thrashMetal->parent_id);

        // Verify ancestor counts
        $this->assertEquals(0, $rock->ancestors()->count());
        $this->assertEquals(1, $altRock->ancestors()->count());
        $this->assertEquals(2, $indieRock->ancestors()->count());
        $this->assertEquals(2, $grunge->ancestors()->count());
        $this->assertEquals(1, $heavyMetal->ancestors()->count());
        $this->assertEquals(2, $thrashMetal->ancestors()->count());
    }

    #[Test]
    public function it_handles_duplicate_slugs_with_different_mbids(): void
    {
        // Create a genre with same slug but different mbid
        Genre::factory()->create([
            'name' => 'Rock',
            'slug' => 'rock',
            'mbid' => '6ba7b826-9dad-11d1-80b4-00c04fd430c8',
        ]);

        $hierarchyData = [
            [
                'name' => 'Rock',
                'mbid' => '6ba7b827-9dad-11d1-80b4-00c04fd430c8',
                'children' => [
                    [
                        'name' => 'Hard Rock',
                        'mbid' => '6ba7b815-9dad-11d1-80b4-00c04fd430c8',
                    ],
                ],
            ],
        ];

        $stats = $this->persister->persistHierarchy($hierarchyData);

        // Should update existing Rock (by slug match)
        $this->assertEquals(1, $stats['created']); // Hard Rock
        $this->assertEquals(1, $stats['updated']); // Rock

        // Verify Rock was updated
        $rock = Genre::where('slug', 'rock')->first();
        $this->assertEquals('6ba7b827-9dad-11d1-80b4-00c04fd430c8', $rock->mbid);

        // Verify we still only have one Rock
        $this->assertEquals(1, Genre::where('slug', 'rock')->count());
    }

    /* ========================================
       TREE QUERY TESTS
       ======================================== */

    #[Test]
    public function it_gets_genre_tree(): void
    {
        // Arrange: Create a genre hierarchy
        $hierarchyData = [
            [
                'name' => 'Rock',
                'mbid' => '6ba7b836-9dad-11d1-80b4-00c04fd430c8',
                'children' => [
                    [
                        'name' => 'Hard Rock',
                        'mbid' => '6ba7b815-9dad-11d1-80b4-00c04fd430c8',
                        'children' => [
                            ['name' => 'Classic Rock', 'mbid' => '6ba7b81a-9dad-11d1-80b4-00c04fd430c8'],
                        ],
                    ],
                    [
                        'name' => 'Punk Rock',
                        'mbid' => '6ba7b816-9dad-11d1-80b4-00c04fd430c8',
                    ],
                ],
            ],
            [
                'name' => 'Electronic',
                'mbid' => '550e8400-e29b-41d4-a716-446655440000',
                'children' => [
                    ['name' => 'Techno', 'mbid' => '6ba7b81d-9dad-11d1-80b4-00c04fd430c8'],
                    ['name' => 'House', 'mbid' => '6ba7b810-9dad-11d1-80b4-00c04fd430c8'],
                ],
            ],
        ];

        // Persist the hierarchy
        $stats = $this->persister->persistHierarchy($hierarchyData);
        $this->assertEquals(7, $stats['created']);

        // Act: Get the genre tree
        $tree = $this->persister->getGenreTree();

        // Assert: Verify tree structure
        $this->assertIsArray($tree);
        $this->assertCount(2, $tree); // Two root genres: Rock and Electronic

        // Verify first root genre (Rock)
        $this->assertEquals('Rock', $tree[0]['name']);
        $this->assertEquals('rock', $tree[0]['slug']);
        $this->assertEquals('6ba7b836-9dad-11d1-80b4-00c04fd430c8', $tree[0]['mbid']);
        $this->assertNull($tree[0]['parent_id']);
        $this->assertIsArray($tree[0]['children']);
        $this->assertCount(2, $tree[0]['children']);

        // Verify Rock's children
        $rockChildren = $tree[0]['children'];
        $this->assertEquals('Hard Rock', $rockChildren[0]['name']);
        $this->assertEquals('hard-rock', $rockChildren[0]['slug']);
        $this->assertEquals('Punk Rock', $rockChildren[1]['name']);
        $this->assertEquals('punk-rock', $rockChildren[1]['slug']);

        // Verify Hard Rock's children (nested)
        $hardRockChildren = $rockChildren[0]['children'];
        $this->assertCount(1, $hardRockChildren);
        $this->assertEquals('Classic Rock', $hardRockChildren[0]['name']);

        // Verify second root genre (Electronic)
        $this->assertEquals('Electronic', $tree[1]['name']);
        $this->assertEquals('electronic', $tree[1]['slug']);
        $this->assertCount(2, $tree[1]['children']);
    }

    #[Test]
    public function it_gets_genre_descendants(): void
    {
        // Arrange: Create a genre hierarchy with multiple levels
        $hierarchyData = [
            [
                'name' => 'Rock',
                'mbid' => '6ba7b836-9dad-11d1-80b4-00c04fd430c8',
                'children' => [
                    [
                        'name' => 'Hard Rock',
                        'mbid' => '6ba7b815-9dad-11d1-80b4-00c04fd430c8',
                        'children' => [
                            ['name' => 'Classic Rock', 'mbid' => '6ba7b81a-9dad-11d1-80b4-00c04fd430c8'],
                            ['name' => 'Southern Rock', 'mbid' => '6ba7b828-9dad-11d1-80b4-00c04fd430c8'],
                        ],
                    ],
                    ['name' => 'Punk Rock', 'mbid' => '6ba7b816-9dad-11d1-80b4-00c04fd430c8'],
                ],
            ],
        ];

        $this->persister->persistHierarchy($hierarchyData);

        // Act: Get descendants of Rock
        $descendants = $this->persister->getGenreDescendants('rock');

        // Assert: Verify all descendants are returned
        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $descendants);
        $this->assertCount(4, $descendants); // Hard Rock, Classic Rock, Southern Rock, Punk Rock

        // Verify descendant names
        $descendantNames = $descendants->pluck('name')->sort()->values()->toArray();
        $this->assertEquals(['Classic Rock', 'Hard Rock', 'Punk Rock', 'Southern Rock'], $descendantNames);

        // Verify depth levels (using ancestors count as proxy)
        $hardRock = $descendants->firstWhere('slug', 'hard-rock');
        $classicRock = $descendants->firstWhere('slug', 'classic-rock');
        $this->assertEquals(1, $hardRock->ancestors()->count()); // Direct child
        $this->assertEquals(2, $classicRock->ancestors()->count()); // Grandchild
    }

    #[Test]
    public function it_gets_genre_ancestors(): void
    {
        // Arrange: Create a deep genre hierarchy
        $hierarchyData = [
            [
                'name' => 'Rock',
                'mbid' => '6ba7b836-9dad-11d1-80b4-00c04fd430c8',
                'children' => [
                    [
                        'name' => 'Hard Rock',
                        'mbid' => '6ba7b815-9dad-11d1-80b4-00c04fd430c8',
                        'children' => [
                            ['name' => 'Classic Rock', 'mbid' => '6ba7b81a-9dad-11d1-80b4-00c04fd430c8'],
                        ],
                    ],
                ],
            ],
        ];

        $this->persister->persistHierarchy($hierarchyData);

        // Act: Get ancestors of Classic Rock
        $ancestors = $this->persister->getGenreAncestors('classic-rock');

        // Assert: Verify all ancestors are returned
        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $ancestors);
        $this->assertCount(2, $ancestors); // Hard Rock and Rock

        // Verify ancestor names (should be ordered from immediate parent to root)
        $ancestorNames = $ancestors->pluck('name')->toArray();
        $this->assertEquals(['Hard Rock', 'Rock'], $ancestorNames);

        // Verify first ancestor is immediate parent
        $this->assertEquals('Hard Rock', $ancestors->first()->name);
        $this->assertEquals('hard-rock', $ancestors->first()->slug);

        // Verify last ancestor is root
        $this->assertEquals('Rock', $ancestors->last()->name);
        $this->assertEquals('rock', $ancestors->last()->slug);
        $this->assertNull($ancestors->last()->parent_id);
    }

    #[Test]
    public function it_handles_deep_hierarchy(): void
    {
        // Arrange: Create a hierarchy with 5 levels of nesting
        $hierarchyData = [
            [
                'name' => 'Root Genre',
                'mbid' => '6ba7b829-9dad-11d1-80b4-00c04fd430c8',
                'children' => [
                    [
                        'name' => 'Level 2',
                        'mbid' => '6ba7b82a-9dad-11d1-80b4-00c04fd430c8',
                        'children' => [
                            [
                                'name' => 'Level 3',
                                'mbid' => '6ba7b82b-9dad-11d1-80b4-00c04fd430c8',
                                'children' => [
                                    [
                                        'name' => 'Level 4',
                                        'mbid' => '6ba7b82c-9dad-11d1-80b4-00c04fd430c8',
                                        'children' => [
                                            ['name' => 'Level 5', 'mbid' => '6ba7b82d-9dad-11d1-80b4-00c04fd430c8'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->persister->persistHierarchy($hierarchyData);

        // Act & Assert: Verify the complete tree structure
        $tree = $this->persister->getGenreTree();
        $this->assertCount(1, $tree);
        $root = $tree[0];
        $this->assertEquals('Root Genre', $root['name']);

        // Navigate through nested children
        $level2 = $root['children'][0];
        $this->assertEquals('Level 2', $level2['name']);

        $level3 = $level2['children'][0];
        $this->assertEquals('Level 3', $level3['name']);

        $level4 = $level3['children'][0];
        $this->assertEquals('Level 4', $level4['name']);

        $level5 = $level4['children'][0];
        $this->assertEquals('Level 5', $level5['name']);

        // Test descendants from root
        $descendants = $this->persister->getGenreDescendants('root-genre');
        $this->assertCount(4, $descendants); // Levels 2, 3, 4, 5

        // Verify depth increases correctly (using ancestors count as proxy for depth)
        $ancestorCounts = $descendants->map(fn ($d) => $d->ancestors()->count())->unique()->sort()->values()->toArray();
        $this->assertEquals([1, 2, 3, 4], $ancestorCounts);

        // Test ancestors from deepest level
        $ancestors = $this->persister->getGenreAncestors('level-5');
        $this->assertCount(4, $ancestors); // Levels 4, 3, 2, Root

        // Verify ancestor chain is complete
        $ancestorNames = $ancestors->pluck('name')->toArray();
        $this->assertEquals(['Level 4', 'Level 3', 'Level 2', 'Root Genre'], $ancestorNames);
    }

    #[Test]
    public function it_returns_empty_collection_for_nonexistent_genre(): void
    {
        // Act: Try to get descendants of non-existent genre
        $descendants = $this->persister->getGenreDescendants('nonexistent-genre');

        // Assert: Should return empty collection
        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $descendants);
        $this->assertCount(0, $descendants);
        $this->assertTrue($descendants->isEmpty());

        // Act: Try to get ancestors of non-existent genre
        $ancestors = $this->persister->getGenreAncestors('nonexistent-genre');

        // Assert: Should return empty collection
        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $ancestors);
        $this->assertCount(0, $ancestors);
        $this->assertTrue($ancestors->isEmpty());
    }

    /* ========================================
       HIERARCHICAL RELATIONSHIP TESTS
       ======================================== */

    #[Test]
    public function it_navigates_parent_child_relationships(): void
    {
        // Arrange: Create genres with parent-child relationships
        $hierarchyData = [
            [
                'name' => 'Electronic',
                'mbid' => '550e8400-e29b-41d4-a716-446655440000',
                'children' => [
                    ['name' => 'Techno', 'mbid' => '6ba7b81d-9dad-11d1-80b4-00c04fd430c8'],
                    ['name' => 'House', 'mbid' => '6ba7b810-9dad-11d1-80b4-00c04fd430c8'],
                ],
            ],
        ];

        $this->persister->persistHierarchy($hierarchyData);

        // Get the genres from database
        $electronic = Genre::where('slug', 'electronic')->first();
        $techno = Genre::where('slug', 'techno')->first();

        // Assert: Verify parent relationship
        $this->assertNotNull($techno->parent_id);
        $this->assertEquals($electronic->id, $techno->parent_id);

        // Test parent() method from HasRecursiveRelationships
        if (method_exists($techno, 'parent')) {
            $parent = $techno->parent;
            $this->assertNotNull($parent);
            $this->assertEquals('Electronic', $parent->name);
            $this->assertEquals('electronic', $parent->slug);
        }

        // Test children() method from HasRecursiveRelationships
        if (method_exists($electronic, 'children')) {
            $children = $electronic->children;
            $this->assertCount(2, $children);
            $this->assertContains('Techno', $children->pluck('name')->toArray());
            $this->assertContains('House', $children->pluck('name')->toArray());
        }

        // Verify reverse relationship
        $this->assertNull($electronic->parent_id); // Electronic is a root genre
    }

    #[Test]
    public function it_calculates_depth_correctly(): void
    {
        // Arrange: Create a hierarchy with multiple levels
        $hierarchyData = [
            [
                'name' => 'Root',
                'mbid' => '6ba7b829-9dad-11d1-80b4-00c04fd430c8',
                'children' => [
                    [
                        'name' => 'Child',
                        'mbid' => '6ba7b82e-9dad-11d1-80b4-00c04fd430c8',
                        'children' => [
                            ['name' => 'Grandchild', 'mbid' => '6ba7b82f-9dad-11d1-80b4-00c04fd430c8'],
                        ],
                    ],
                ],
            ],
        ];

        $this->persister->persistHierarchy($hierarchyData);

        // Get all genres
        $root = Genre::where('slug', 'root')->first();
        $child = Genre::where('slug', 'child')->first();
        $grandchild = Genre::where('slug', 'grandchild')->first();

        // Verify depth by counting ancestors
        $this->assertEquals(0, $root->ancestors()->count()); // Root has no ancestors
        $this->assertEquals(1, $child->ancestors()->count()); // Child has 1 ancestor (Root)
        $this->assertEquals(2, $grandchild->ancestors()->count()); // Grandchild has 2 ancestors (Child, Root)

        // Verify depth in descendants query (using ancestors count as alternative)
        $descendants = $this->persister->getGenreDescendants('root');
        $childFromQuery = $descendants->firstWhere('slug', 'child');
        $grandchildFromQuery = $descendants->firstWhere('slug', 'grandchild');

        $this->assertEquals(1, $childFromQuery->ancestors()->count());
        $this->assertEquals(2, $grandchildFromQuery->ancestors()->count());
    }

    #[Test]
    public function it_handles_multiple_root_genres(): void
    {
        // Arrange: Create a tree with multiple top-level genres
        $hierarchyData = [
            [
                'name' => 'Rock',
                'mbid' => '6ba7b836-9dad-11d1-80b4-00c04fd430c8',
                'children' => [
                    ['name' => 'Hard Rock', 'mbid' => '6ba7b815-9dad-11d1-80b4-00c04fd430c8'],
                ],
            ],
            [
                'name' => 'Electronic',
                'mbid' => '550e8400-e29b-41d4-a716-446655440000',
                'children' => [
                    ['name' => 'Techno', 'mbid' => '6ba7b81d-9dad-11d1-80b4-00c04fd430c8'],
                ],
            ],
            [
                'name' => 'Jazz',
                'mbid' => '6ba7b812-9dad-11d1-80b4-00c04fd430c8',
                'children' => [
                    ['name' => 'Bebop', 'mbid' => '6ba7b813-9dad-11d1-80b4-00c04fd430c8'],
                ],
            ],
        ];

        $this->persister->persistHierarchy($hierarchyData);

        // Act: Get the complete genre tree
        $tree = $this->persister->getGenreTree();

        // Assert: Verify three root genres
        $this->assertCount(3, $tree);

        // Verify all root genres have no parent
        collect($tree)->each(function ($rootGenre) {
            $this->assertNull($rootGenre['parent_id']);
        });

        // Verify root genre names
        $rootNames = collect($tree)->pluck('name')->sort()->values()->toArray();
        $this->assertEquals(['Electronic', 'Jazz', 'Rock'], $rootNames);

        // Verify each root has its own children
        $rockTree = collect($tree)->firstWhere('name', 'Rock');
        $electronicTree = collect($tree)->firstWhere('name', 'Electronic');
        $jazzTree = collect($tree)->firstWhere('name', 'Jazz');

        $this->assertCount(1, $rockTree['children']);
        $this->assertCount(1, $electronicTree['children']);
        $this->assertCount(1, $jazzTree['children']);

        $this->assertEquals('Hard Rock', $rockTree['children'][0]['name']);
        $this->assertEquals('Techno', $electronicTree['children'][0]['name']);
        $this->assertEquals('Bebop', $jazzTree['children'][0]['name']);

        // Verify no cross-contamination between trees
        $rockDescendants = $this->persister->getGenreDescendants('rock');
        $this->assertCount(1, $rockDescendants);
        $this->assertEquals('Hard Rock', $rockDescendants->first()->name);

        $electronicDescendants = $this->persister->getGenreDescendants('electronic');
        $this->assertCount(1, $electronicDescendants);
        $this->assertEquals('Techno', $electronicDescendants->first()->name);
    }

    /* ========================================
       INTEGRATION WITH HIERARCHY SERVICE TESTS
       ======================================== */

    #[Test]
    public function it_builds_and_persists_complete_hierarchy(): void
    {
        // Arrange: Create a complex hierarchy data structure
        $hierarchyData = [
            [
                'name' => 'Rock',
                'mbid' => '1f4df26d-71d4-4494-b839-5df5c4bbfcfa',
                'children' => [
                    [
                        'name' => 'Hard Rock',
                        'mbid' => 'a3b8c9d2-1e4f-4a5b-8c3d-9e6f1a2b3c4d',
                        'children' => [
                            ['name' => 'Classic Rock', 'mbid' => '6ba7b844-9dad-11d1-80b4-00c04fd430c8'],
                            ['name' => 'Southern Rock', 'mbid' => '6ba7b845-9dad-11d1-80b4-00c04fd430c8'],
                        ],
                    ],
                    [
                        'name' => 'Punk Rock',
                        'mbid' => '6ba7b843-9dad-11d1-80b4-00c04fd430c8',
                        'children' => [
                            ['name' => 'Hardcore Punk', 'mbid' => '6ba7b842-9dad-11d1-80b4-00c04fd430c8'],
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Electronic',
                'mbid' => '6ba7b83d-9dad-11d1-80b4-00c04fd430c8',
                'children' => [
                    ['name' => 'Techno', 'mbid' => '6ba7b83e-9dad-11d1-80b4-00c04fd430c8'],
                    ['name' => 'House', 'mbid' => '6ba7b83f-9dad-11d1-80b4-00c04fd430c8'],
                    ['name' => 'Ambient', 'mbid' => '6ba7b840-9dad-11d1-80b4-00c04fd430c8'],
                ],
            ],
        ];

        // Act: Persist the complete hierarchy
        $stats = $this->persister->persistHierarchy($hierarchyData);

        // Assert: Verify persistence statistics
        $this->assertArrayHasKey('created', $stats);
        $this->assertArrayHasKey('updated', $stats);
        $this->assertArrayHasKey('linked', $stats);
        $this->assertArrayHasKey('errors', $stats);

        // All genres should be created (10 total)
        $this->assertEquals(10, $stats['created']);
        $this->assertCount(0, $stats['errors']);

        // Verify database has all genres
        $this->assertEquals(10, Genre::count());

        // Verify root genres in database
        $rootGenres = Genre::whereNull('parent_id')->get();
        $this->assertCount(2, $rootGenres);
        $rootNames = $rootGenres->pluck('name')->sort()->values()->toArray();
        $this->assertEquals(['Electronic', 'Rock'], $rootNames);

        // Verify parent-child relationships in database
        $rock = Genre::where('slug', 'rock')->first();
        $hardRock = Genre::where('slug', 'hard-rock')->first();
        $classicRock = Genre::where('slug', 'classic-rock')->first();

        $this->assertEquals($rock->id, $hardRock->parent_id);
        $this->assertEquals($hardRock->id, $classicRock->parent_id);

        // Verify all slugs are generated correctly
        $expectedSlugs = [
            'rock', 'hard-rock', 'classic-rock', 'southern-rock',
            'punk-rock', 'hardcore-punk',
            'electronic', 'techno', 'house', 'ambient'
        ];
        sort($expectedSlugs);
        $actualSlugs = Genre::pluck('slug')->sort()->values()->toArray();
        $this->assertEquals($expectedSlugs, $actualSlugs);

        // Verify all mbids are preserved
        $rockMbids = Genre::whereNotNull('mbid')->get();
        $this->assertCount(10, $rockMbids);
    }

    #[Test]
    public function it_queries_persisted_hierarchy(): void
    {
        // Arrange: Build and persist a hierarchy
        $hierarchyData = [
            [
                'name' => 'Pop',
                'mbid' => '6ba7b830-9dad-11d1-80b4-00c04fd430c8',
                'children' => [
                    [
                        'name' => 'Synth-pop',
                        'mbid' => '6ba7b831-9dad-11d1-80b4-00c04fd430c8',
                        'children' => [
                            ['name' => 'Dream Pop', 'mbid' => '6ba7b832-9dad-11d1-80b4-00c04fd430c8'],
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Hip Hop',
                'mbid' => '6ba7b833-9dad-11d1-80b4-00c04fd430c8',
                'children' => [
                    ['name' => 'Trap', 'mbid' => '6ba7b834-9dad-11d1-80b4-00c04fd430c8'],
                    ['name' => 'Boom Bap', 'mbid' => '6ba7b835-9dad-11d1-80b4-00c04fd430c8'],
                ],
            ],
        ];

        $this->persister->persistHierarchy($hierarchyData);

        // Act & Assert: Query and verify the complete tree
        $tree = $this->persister->getGenreTree();
        $this->assertCount(2, $tree);

        // Test descendants query from Pop
        $popDescendants = $this->persister->getGenreDescendants('pop');
        $this->assertCount(2, $popDescendants);
        $popDescendantNames = $popDescendants->pluck('name')->sort()->values()->toArray();
        $this->assertEquals(['Dream Pop', 'Synth-pop'], $popDescendantNames);

        // Test ancestors query from Dream Pop
        $dreamPopAncestors = $this->persister->getGenreAncestors('dream-pop');
        $this->assertCount(2, $dreamPopAncestors);
        $ancestorNames = $dreamPopAncestors->pluck('name')->toArray();
        $this->assertEquals(['Synth-pop', 'Pop'], $ancestorNames);

        // Verify HasRecursiveRelationships methods work on persisted data
        $pop = Genre::where('slug', 'pop')->first();
        if (method_exists($pop, 'descendants')) {
            $popDescendantsFromModel = $pop->descendants;
            $this->assertCount(2, $popDescendantsFromModel);
        }

        $dreamPop = Genre::where('slug', 'dream-pop')->first();
        if (method_exists($dreamPop, 'ancestors')) {
            $dreamPopAncestorsFromModel = $dreamPop->ancestors;
            $this->assertCount(2, $dreamPopAncestorsFromModel);
        }

        // Verify depth calculation across the tree
        // Depth is available through descendants/ancestors queries
        $this->assertEquals(0, $pop->ancestors()->count());
        $this->assertEquals(1, Genre::where('slug', 'synth-pop')->first()->ancestors()->count());
        $this->assertEquals(2, $dreamPop->ancestors()->count());

        // Test that tree can be rebuilt from persisted data
        $rebuiltTree = $this->persister->getGenreTree();
        $this->assertEquals($tree, $rebuiltTree);

        // Verify navigation from leaf to root and back
        $trap = Genre::where('slug', 'trap')->first();
        $trapAncestors = $this->persister->getGenreAncestors('trap');
        $this->assertCount(1, $trapAncestors);
        $this->assertEquals('Hip Hop', $trapAncestors->first()->name);

        $hipHopDescendants = $this->persister->getGenreDescendants('hip-hop');
        $this->assertCount(2, $hipHopDescendants);
        $hipHopDescendantNames = $hipHopDescendants->pluck('name')->sort()->values()->toArray();
        $this->assertEquals(['Boom Bap', 'Trap'], $hipHopDescendantNames);
    }

    #[Test]
    public function it_handles_re_persisting_existing_hierarchy(): void
    {
        // Arrange: Create initial hierarchy
        $initialHierarchy = [
            [
                'name' => 'Rock',
                'mbid' => '6ba7b836-9dad-11d1-80b4-00c04fd430c8',
                'children' => [
                    ['name' => 'Hard Rock', 'mbid' => '6ba7b815-9dad-11d1-80b4-00c04fd430c8'],
                ],
            ],
        ];

        $stats1 = $this->persister->persistHierarchy($initialHierarchy);
        $this->assertEquals(2, $stats1['created']);

        // Act: Re-persist with updates
        $updatedHierarchy = [
            [
                'name' => 'Rock',
                'mbid' => '6ba7b837-9dad-11d1-80b4-00c04fd430c8', // Updated MBID
                'children' => [
                    ['name' => 'Hard Rock', 'mbid' => '6ba7b815-9dad-11d1-80b4-00c04fd430c8'],
                    ['name' => 'Punk Rock', 'mbid' => '6ba7b816-9dad-11d1-80b4-00c04fd430c8'], // New child
                ],
            ],
        ];

        $stats2 = $this->persister->persistHierarchy($updatedHierarchy, ['update_existing' => true]);

        // Assert: Verify updates
        $this->assertEquals(1, $stats2['created']); // Punk Rock added
        $this->assertGreaterThanOrEqual(1, $stats2['updated']); // Rock updated

        // Verify Rock's MBID was updated
        $rock = Genre::where('slug', 'rock')->first();
        $this->assertEquals('6ba7b837-9dad-11d1-80b4-00c04fd430c8', $rock->mbid);

        // Verify new child was added
        $punkRock = Genre::where('slug', 'punk-rock')->first();
        $this->assertNotNull($punkRock);
        $this->assertEquals($rock->id, $punkRock->parent_id);

        // Verify tree structure is correct
        $tree = $this->persister->getGenreTree();
        $this->assertCount(1, $tree);
        $this->assertCount(2, $tree[0]['children']);

        $childNames = collect($tree[0]['children'])->pluck('name')->sort()->values()->toArray();
        $this->assertEquals(['Hard Rock', 'Punk Rock'], $childNames);
    }

    #[Test]
    public function it_handles_empty_and_single_node_trees(): void
    {
        // Test empty tree
        $emptyTree = $this->persister->getGenreTree();
        $this->assertIsArray($emptyTree);
        $this->assertCount(0, $emptyTree);

        // Test single node (no children)
        $singleNodeHierarchy = [
            [
                'name' => 'Classical',
                'mbid' => '6ba7b838-9dad-11d1-80b4-00c04fd430c8',
            ],
        ];

        $stats = $this->persister->persistHierarchy($singleNodeHierarchy);
        $this->assertEquals(1, $stats['created']);

        $tree = $this->persister->getGenreTree();
        $this->assertCount(1, $tree);
        $this->assertEquals('Classical', $tree[0]['name']);
        $this->assertCount(0, $tree[0]['children']);

        // Test descendants of single node
        $descendants = $this->persister->getGenreDescendants('classical');
        $this->assertCount(0, $descendants);

        // Test ancestors of root node
        $ancestors = $this->persister->getGenreAncestors('classical');
        $this->assertCount(0, $ancestors);
    }
}
