<?php

namespace Tests\Concerns;

use App\Models\Album;
use App\Models\Artist;
use App\Models\Library;
use App\Models\Song;

trait InteractsWithMetadata
{
    /**
     * Create a library with associated media including albums, artists, and songs.
     *
     * @param int $albumsCount Number of albums to create
     * @param int $songsPerAlbum Number of songs per album
     * @param int $artistsCount Number of artists to create
     * @param array $libraryOverrides Additional attributes for the library
     * @return Library
     */
    protected function createLibraryWithMedia(
        int $albumsCount = 3,
        int $songsPerAlbum = 5,
        int $artistsCount = 2,
        array $libraryOverrides = []
    ): Library {
        $library = Library::factory()->create($libraryOverrides);

        $artists = Artist::factory()->count($artistsCount)->create();

        $albums = Album::factory()
            ->count($albumsCount)
            ->create(['library_id' => $library->id]);

        foreach ($albums as $album) {
            // Attach random artists to album
            $album->artists()->attach(
                $artists->random(rand(1, $artistsCount))->pluck('id')
            );

            // Create songs for the album
            Song::factory()
                ->count($songsPerAlbum)
                ->create(['album_id' => $album->id]);

            // Attach artists to songs
            foreach ($album->songs as $song) {
                $song->artists()->attach(
                    $artists->random(rand(1, $artistsCount))->pluck('id')
                );
            }
        }

        return $library->load(['albums.songs', 'albums.artists']);
    }

    /**
     * Create an album with specified number of songs.
     *
     * @param int $count Number of songs to create
     * @param array $albumOverrides Additional attributes for the album
     * @param array $songOverrides Additional attributes for songs (closure)
     * @return Album
     */
    protected function createAlbumWithSongs(
        int $count = 5,
        array $albumOverrides = [],
        array $songOverrides = []
    ): Album {
        $album = Album::factory()->create($albumOverrides);

        for ($i = 0; $i < $count; $i++) {
            $overrides = is_callable($songOverrides)
                ? $songOverrides($i, $album)
                : $songOverrides;

            Song::factory()->create(array_merge([
                'album_id' => $album->id,
            ], $overrides));
        }

        return $album->load('songs');
    }

    /**
     * Create an artist with specified number of albums.
     *
     * @param int $count Number of albums to create
     * @param int $songsPerAlbum Number of songs per album
     * @param array $artistOverrides Additional attributes for the artist
     * @param array $albumOverrides Additional attributes for albums (closure)
     * @return Artist
     */
    protected function createArtistWithAlbums(
        int $count = 3,
        int $songsPerAlbum = 5,
        array $artistOverrides = [],
        array $albumOverrides = []
    ): Artist {
        $artist = Artist::factory()->create($artistOverrides);

        $albums = [];
        for ($i = 0; $i < $count; $i++) {
            $overrides = is_callable($albumOverrides)
                ? $albumOverrides($i, $artist)
                : $albumOverrides;

            $album = Album::factory()->create($overrides);
            $album->artists()->attach($artist->id);

            // Create songs for this album
            Song::factory()
                ->count($songsPerAlbum)
                ->create(['album_id' => $album->id]);

            // Attach artist to songs
            foreach ($album->songs as $song) {
                $song->artists()->attach($artist->id);
            }

            $albums[] = $album;
        }

        return $artist->load(['albums.songs']);
    }

    /**
     * Create a song with an album and artist.
     *
     * @param array $songOverrides Additional attributes for the song
     * @param array $albumOverrides Additional attributes for the album
     * @param array $artistOverrides Additional attributes for the artist
     * @return Song
     */
    protected function createSongWithAlbumAndArtist(
        array $songOverrides = [],
        array $albumOverrides = [],
        array $artistOverrides = []
    ): Song {
        $artist = Artist::factory()->create($artistOverrides);

        $album = Album::factory()->create($albumOverrides);
        $album->artists()->attach($artist->id);

        $song = Song::factory()->create(array_merge([
            'album_id' => $album->id,
        ], $songOverrides));

        $song->artists()->attach($artist->id);

        return $song->load(['album', 'album.artists', 'artists']);
    }

    /**
     * Create multiple albums for a library.
     *
     * @param Library|int $library Library instance or ID
     * @param int $count Number of albums to create
     * @param array $overrides Additional attributes for albums
     * @return \Illuminate\Database\Eloquent\Collection
     */
    protected function createAlbumsForLibrary(
        Library|int $library,
        int $count = 3,
        array $overrides = []
    ) {
        $libraryId = is_numeric($library) ? $library : $library->id;

        return Album::factory()
            ->count($count)
            ->create(array_merge($overrides, ['library_id' => $libraryId]));
    }

    /**
     * Create multiple songs for an album.
     *
     * @param Album|int $album Album instance or ID
     * @param int $count Number of songs to create
     * @param array $overrides Additional attributes for songs
     * @return \Illuminate\Database\Eloquent\Collection
     */
    protected function createSongsForAlbum(
        Album|int $album,
        int $count = 5,
        array $overrides = []
    ) {
        $albumId = is_numeric($album) ? $album : $album->id;

        return Song::factory()
            ->count($count)
            ->create(array_merge($overrides, ['album_id' => $albumId]));
    }
}
