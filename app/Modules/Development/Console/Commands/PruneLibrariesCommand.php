<?php

namespace App\Modules\Development\Console\Commands;

use App\Modules\Development\Console\DevelopmentCommand;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Command\Command;
use App\Models\Library;
use Illuminate\Support\Facades\Schema;


class PruneLibrariesCommand extends \Illuminate\Console\Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'prune:libraries';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Prune all libraries';

    /**
     * Execute the console command.
     */

    public function handle(): int
    {

        $library = Library::first();

        if (!$library) {
            $this->error('No libraries found to prune.');
            return Command::FAILURE;
        }

        $this->info("Truncating media data for library: {$library->slug}");

        DB::transaction(function () use ($library) {
            // Get counts before deletion for reporting
            $albumCount = $library->albums()->count();
            $songCount = DB::table('songs')
                ->whereIn('album_id', $library->albums()->pluck('id'))
                ->count();

            // Delete related pivot table data first (PostgreSQL handles FK constraints automatically)
            $albumIds = $library->albums()->pluck('id')->toArray();
            $songIds = DB::table('songs')->whereIn('album_id', $albumIds)->pluck('id')->toArray();

            if (!empty($songIds)) {
                // Genre-Song relationships
                if (Schema::hasTable('genre_song')) {
                    DB::table('genre_song')->whereIn('song_id', $songIds)->delete();
                }

                // Artist-Song relationships
                if (Schema::hasTable('artist_song')) {
                    DB::table('artist_song')->whereIn('song_id', $songIds)->delete();
                }

                // User media activities
                if (Schema::hasTable('user_media_activities')) {
                    DB::table('user_media_activities')
                        ->where('user_media_activityable_type', 'App\\Models\\Song')
                        ->whereIn('user_media_activityable_id', $songIds)
                        ->delete();
                }
            }

            if (!empty($albumIds)) {
                // Album-Artist relationships
                if (Schema::hasTable('album_artist')) {
                    DB::table('album_artist')->whereIn('album_id', $albumIds)->delete();
                }

                // Album covers (images)
                if (Schema::hasTable('images')) {
                    DB::table('images')
                        ->where('imageable_type', 'App\\Models\\Album')
                        ->whereIn('imageable_id', $albumIds)
                        ->delete();
                }

                // Media library (Spatie Media Library if used)
                if (Schema::hasTable('media')) {
                    DB::table('media')
                        ->where('model_type', 'App\\Models\\Album')
                        ->whereIn('model_id', $albumIds)
                        ->delete();
                }
            }

            // Delete songs
            DB::table('songs')->whereIn('album_id', $albumIds)->delete();

            // Delete albums
            DB::table('albums')->where('library_id', $library->id)->delete();

            // Clean up orphaned artists (artists with no albums)
            $orphanedArtists = DB::table('artists')
                ->leftJoin('album_artist', 'artists.id', '=', 'album_artist.artist_id')
                ->leftJoin('artist_song', 'artists.id', '=', 'artist_song.artist_id')
                ->whereNull('album_artist.artist_id')
                ->whereNull('artist_song.artist_id')
                ->pluck('artists.id');

            if ($orphanedArtists->isNotEmpty()) {
                // Clean up artist images
                if (Schema::hasTable('images')) {
                    DB::table('images')
                        ->where('imageable_type', 'App\\Models\\Artist')
                        ->whereIn('imageable_id', $orphanedArtists)
                        ->delete();
                }

                // Clean up artist media
                if (Schema::hasTable('media')) {
                    DB::table('media')
                        ->where('model_type', 'App\\Models\\Artist')
                        ->whereIn('model_id', $orphanedArtists)
                        ->delete();
                }

                DB::table('artists')->whereIn('id', $orphanedArtists)->delete();
            }

            // Clean up orphaned genres (genres with no songs)
            $orphanedGenres = DB::table('genres')
                ->leftJoin('genre_song', 'genres.id', '=', 'genre_song.genre_id')
                ->whereNull('genre_song.genre_id')
                ->pluck('genres.id');

            if ($orphanedGenres->isNotEmpty()) {
                DB::table('genres')->whereIn('id', $orphanedGenres)->delete();
            }

            $this->info("✓ Deleted {$albumCount} albums and {$songCount} songs from library '{$library->slug}'");
            $this->info("✓ Cleaned up orphaned artists: " . $orphanedArtists->count());
            $this->info("✓ Cleaned up orphaned genres: " . $orphanedGenres->count());
        });

        $this->info("Media data truncation completed for library: {$library->slug}");

        Redis::command('flushall');

        Storage::disk('local')->deleteDirectory('images/covers');
        Storage::disk('local')->makeDirectory('images/covers');
        Storage::disk('local')->put('images/covers/.gitignore', "*\n!.gitignore");

        return Command::SUCCESS;

    }
}
