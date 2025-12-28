<?php

namespace Tests\Unit\Services;

use App\Models\Genre;
use App\Models\User;
use PHPUnit\Framework\Attributes\Test;

class GenreHierarchyServiceMusicBrainzTest extends ServiceTestCase
{
    #[Test]
    public function it_handles_parent_id_mass_assignment(): void
    {
        // Create a parent genre
        $parentGenre = Genre::create([
            'name' => 'rock',
            'slug' => 'rock',
        ]);

        // Create a child genre with parent_id
        $childGenre = Genre::create([
            'name' => 'hard rock',
            'slug' => 'hard-rock',
            'parent_id' => $parentGenre->id,
        ]);

        // Assert parent_id was mass assigned correctly
        $this->assertEquals($parentGenre->id, $childGenre->parent_id);
        $this->assertEquals('rock', $childGenre->parent->name);
    }

    #[Test]
    public function it_handles_mbid_mass_assignment(): void
    {
        // Create a genre with MusicBrainz ID
        $mbid = '1f4df26d-71d4-4494-b839-5df5c4bbfcfa';
        $genre = Genre::create([
            'name' => 'rock',
            'slug' => 'rock',
            'mbid' => $mbid,
        ]);

        // Assert mbid was mass assigned correctly
        $this->assertEquals($mbid, $genre->mbid);
        $this->assertEquals("https://musicbrainz.org/genre/{$mbid}", $genre->music_brainz_url);
    }

    #[Test]
    public function it_creates_parent_child_relationships(): void
    {
        // Create parent and child genres
        $parent = Genre::create([
            'name' => 'electronic',
            'slug' => 'electronic',
        ]);

        $child1 = Genre::create([
            'name' => 'house',
            'slug' => 'house',
            'parent_id' => $parent->id,
        ]);

        $child2 = Genre::create([
            'name' => 'techno',
            'slug' => 'techno',
            'parent_id' => $parent->id,
        ]);

        // Verify relationships using HasRecursiveRelationships trait
        $this->assertCount(2, $parent->children);
        $this->assertTrue($parent->children->contains($child1));
        $this->assertTrue($parent->children->contains($child2));

        $this->assertEquals($parent->id, $child1->parent_id);
        $this->assertEquals($parent->id, $child2->parent_id);

        // Verify ancestor/descendant relationships
        $this->assertTrue($parent->descendants->contains($child1));
        $this->assertTrue($child1->ancestors->contains($parent));
    }

    #[Test]
    public function it_is_idempotent(): void
    {
        // Running the same hierarchy creation twice should not create duplicates
        Genre::firstOrCreate(['name' => 'jazz'], ['slug' => 'jazz']);
        $initialCount = Genre::where('name', 'jazz')->count();

        // Try to create again
        Genre::firstOrCreate(['name' => 'jazz'], ['slug' => 'jazz']);
        $finalCount = Genre::where('name', 'jazz')->count();

        // Should still have only one jazz genre
        $this->assertEquals(1, $initialCount);
        $this->assertEquals(1, $finalCount);
    }

    #[Test]
    public function it_handles_mbid_gracefully_when_null(): void
    {
        // Genre without MusicBrainz data should work fine
        $genre = Genre::create([
            'name' => 'Unknown Genre',
            'slug' => 'unknown-genre',
            'mbid' => null,
        ]);

        $this->assertNull($genre->mbid);
        $this->assertNull($genre->music_brainz_url);
        $this->assertEquals('Unknown Genre', $genre->name);
    }

    #[Test]
    public function it_gets_genre_tree(): void
    {
        // Create a hierarchy: rock -> hard rock, punk rock
        $rock = Genre::create(['name' => 'Rock', 'slug' => 'rock']);
        Genre::create(['name' => 'Hard Rock', 'slug' => 'hard-rock', 'parent_id' => $rock->id]);
        Genre::create(['name' => 'Punk Rock', 'slug' => 'punk-rock', 'parent_id' => $rock->id]);

        // Get root genres (those without parents)
        $rootGenres = Genre::whereNull('parent_id')->get();

        $this->assertCount(1, $rootGenres);
        $this->assertEquals('Rock', $rootGenres->first()->name);

        // Load descendants
        $rock->load('descendants');
        $this->assertCount(2, $rock->descendants);
    }

    #[Test]
    public function it_gets_genre_descendants(): void
    {
        // Create hierarchy: electronic -> house -> deep house
        $electronic = Genre::create(['name' => 'Electronic', 'slug' => 'electronic']);
        $house = Genre::create(['name' => 'House', 'slug' => 'house', 'parent_id' => $electronic->id]);
        Genre::create(['name' => 'Deep House', 'slug' => 'deep-house', 'parent_id' => $house->id]);

        // Get descendants of electronic
        $descendants = $electronic->descendants;

        $this->assertCount(2, $descendants);
        $this->assertTrue($descendants->contains('name', 'House'));
        $this->assertTrue($descendants->contains('name', 'Deep House'));
    }

    #[Test]
    public function it_gets_genre_ancestors(): void
    {
        // Create hierarchy: pop -> dance pop -> euro dance
        $pop = Genre::create(['name' => 'Pop', 'slug' => 'pop']);
        $dancePop = Genre::create(['name' => 'Dance Pop', 'slug' => 'dance-pop', 'parent_id' => $pop->id]);
        $euroDance = Genre::create(['name' => 'Euro Dance', 'slug' => 'euro-dance', 'parent_id' => $dancePop->id]);

        // Get ancestors of euro dance
        $ancestors = $euroDance->ancestors;

        $this->assertCount(2, $ancestors);
        $this->assertTrue($ancestors->contains('name', 'Dance Pop'));
        $this->assertTrue($ancestors->contains('name', 'Pop'));
    }

    #[Test]
    public function it_handles_deep_hierarchy(): void
    {
        // Create a 4-level deep hierarchy
        $level1 = Genre::create(['name' => 'Level 1', 'slug' => 'level-1']);
        $level2 = Genre::create(['name' => 'Level 2', 'slug' => 'level-2', 'parent_id' => $level1->id]);
        $level3 = Genre::create(['name' => 'Level 3', 'slug' => 'level-3', 'parent_id' => $level2->id]);
        $level4 = Genre::create(['name' => 'Level 4', 'slug' => 'level-4', 'parent_id' => $level3->id]);

        // Test descendants count
        $this->assertCount(3, $level1->descendants);

        // Test ancestors count
        $this->assertCount(3, $level4->ancestors);

        // Verify relationships
        $this->assertTrue($level1->descendants->contains($level2));
        $this->assertTrue($level1->descendants->contains($level3));
        $this->assertTrue($level1->descendants->contains($level4));
        $this->assertTrue($level4->ancestors->contains($level1));
    }

    #[Test]
    public function it_handles_multiple_root_genres(): void
    {
        // Create multiple top-level genres with children
        $rock = Genre::create(['name' => 'Rock', 'slug' => 'rock']);
        Genre::create(['name' => 'Hard Rock', 'slug' => 'hard-rock', 'parent_id' => $rock->id]);

        $electronic = Genre::create(['name' => 'Electronic', 'slug' => 'electronic']);
        Genre::create(['name' => 'House', 'slug' => 'house', 'parent_id' => $electronic->id]);

        $jazz = Genre::create(['name' => 'Jazz', 'slug' => 'jazz']);

        // Get all root genres
        $rootGenres = Genre::whereNull('parent_id')->get();

        $this->assertCount(3, $rootGenres);
        $this->assertTrue($rootGenres->contains('name', 'Rock'));
        $this->assertTrue($rootGenres->contains('name', 'Electronic'));
        $this->assertTrue($rootGenres->contains('name', 'Jazz'));
    }

    #[Test]
    public function it_builds_and_persists_complete_hierarchy(): void
    {
        // End-to-end test: build hierarchy and verify it can be persisted

        // Persist to database
        $metal = Genre::create([
            'name' => 'metal',
            'slug' => 'metal',
        ]);

        $heavyMetal = Genre::create([
            'name' => 'heavy metal',
            'slug' => 'heavy-metal',
            'parent_id' => $metal->id,
        ]);

        $thrashMetal = Genre::create([
            'name' => 'thrash metal',
            'slug' => 'thrash-metal',
            'parent_id' => $heavyMetal->id,
        ]);

        // Verify the persisted hierarchy can be queried
        $this->assertCount(2, $metal->descendants);
        $this->assertTrue($metal->descendants->contains($thrashMetal));
        $this->assertTrue($thrashMetal->ancestors->contains($metal));
    }
}
