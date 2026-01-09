<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Tpetry\PostgresqlEnhanced\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Ensure required extensions exist
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        DB::statement('CREATE EXTENSION IF NOT EXISTS "uuid-ossp"');

        // Create function to generate media IDs (using your nano ID system)
        Schema::createFunctionOrReplace(
            name: 'generate_media_id',
            parameters: [],
            return: 'uuid',
            language: 'sql:expression',
            body: 'uuid_generate_v4()',
            options: [
                'parallel'   => 'safe',
                'volatility' => 'volatile',
            ],
        );

        // Create song similarity search function
        Schema::createFunctionOrReplace(
            name: 'search_songs_similarity',
            parameters: [
                'search_term'          => 'text',
                'similarity_threshold' => 'real',
            ],
            return: [
                'id'               => 'bigint',
                'public_id'        => 'varchar',
                'title'            => 'varchar',
                'similarity_score' => 'real',
            ],
            language: 'plpgsql',
            body: "
BEGIN
    RETURN QUERY
    SELECT 
        s.id,
        s.public_id,
        s.title,
        similarity(s.title, search_term) as sim_score
    FROM songs s
    WHERE s.title % search_term
    AND similarity(s.title, search_term) > similarity_threshold
    ORDER BY sim_score DESC;
END;
            ",
            options: [
                'parallel'   => 'safe',
                'volatility' => 'stable',
            ],
        );

        // Create artist similarity search function
        Schema::createFunctionOrReplace(
            name: 'search_artists_similarity',
            parameters: [
                'search_term'          => 'text',
                'similarity_threshold' => 'real',
            ],
            return: [
                'id'               => 'bigint',
                'public_id'        => 'varchar',
                'name'             => 'varchar',
                'similarity_score' => 'real',
            ],
            language: 'plpgsql',
            body: "
BEGIN
    RETURN QUERY
    SELECT 
        a.id,
        a.public_id,
        a.name,
        similarity(a.name, search_term) as sim_score
    FROM artists a
    WHERE a.name % search_term
    AND similarity(a.name, search_term) > similarity_threshold
    ORDER BY sim_score DESC;
END;
            ",
            options: [
                'parallel'   => 'safe',
                'volatility' => 'stable',
            ],
        );

        // Create album similarity search function
        Schema::createFunctionOrReplace(
            name: 'search_albums_similarity',
            parameters: [
                'search_term'          => 'text',
                'similarity_threshold' => 'real',
            ],
            return: [
                'id'               => 'bigint',
                'public_id'        => 'varchar',
                'title'            => 'varchar',
                'year'             => 'integer',
                'similarity_score' => 'real',
            ],
            language: 'plpgsql',
            body: "
BEGIN
    RETURN QUERY
    SELECT 
        al.id,
        al.public_id,
        al.title,
        al.year,
        similarity(al.title, search_term) as sim_score
    FROM albums al
    WHERE al.title % search_term
    AND similarity(al.title, search_term) > similarity_threshold
    ORDER BY sim_score DESC;
END;
            ",
            options: [
                'parallel'   => 'safe',
                'volatility' => 'stable',
            ],
        );

        // Create comprehensive media search function
        Schema::createFunctionOrReplace(
            name: 'search_media_comprehensive',
            parameters: [
                'song_term'      => 'text',
                'artist_term'    => 'text',
                'album_term'     => 'text',
                'min_similarity' => 'real',
            ],
            return: [
                'song_id'           => 'bigint',
                'song_public_id'    => 'varchar',
                'song_title'        => 'varchar',
                'artist_name'       => 'varchar',
                'album_title'       => 'varchar',
                'song_similarity'   => 'real',
                'artist_similarity' => 'real',
                'album_similarity'  => 'real',
                'combined_score'    => 'real',
            ],
            language: 'plpgsql',
            body: "
BEGIN
    RETURN QUERY
    SELECT 
        s.id as song_id,
        s.public_id as song_public_id,
        s.title as song_title,
        STRING_AGG(DISTINCT art.name, ', ') as artist_name,
        al.title as album_title,
        COALESCE(similarity(s.title, song_term), 0) as song_sim,
        COALESCE(MAX(similarity(art.name, artist_term)), 0) as artist_sim,
        COALESCE(similarity(al.title, album_term), 0) as album_sim,
        (COALESCE(similarity(s.title, song_term), 0) + 
         COALESCE(MAX(similarity(art.name, artist_term)), 0) + 
         COALESCE(similarity(al.title, album_term), 0)) / 3 as combined
    FROM songs s
    LEFT JOIN albums al ON s.album_id = al.id
    LEFT JOIN artist_song ast ON s.id = ast.song_id
    LEFT JOIN artists art ON ast.artist_id = art.id
    WHERE (s.title % song_term OR art.name % artist_term OR al.title % album_term)
    GROUP BY s.id, s.public_id, s.title, al.title
    HAVING (COALESCE(similarity(s.title, song_term), 0) + 
            COALESCE(MAX(similarity(art.name, artist_term)), 0) + 
            COALESCE(similarity(al.title, album_term), 0)) / 3 > min_similarity
    ORDER BY combined DESC;
END;
            ",
            options: [
                'parallel'   => 'safe',
                'volatility' => 'stable',
            ],
        );

        // Create library statistics function
        Schema::createFunctionOrReplace(
            name: 'get_library_stats',
            parameters: [
                'library_id' => 'bigint',
            ],
            return: 'json',
            language: 'plpgsql',
            body: "
DECLARE
    result JSON;
    input_library_id BIGINT := library_id;
BEGIN
    SELECT json_build_object(
        'total_songs', COUNT(DISTINCT s.id),
        'total_albums', COUNT(DISTINCT al.id),
        'total_artists', COUNT(DISTINCT art.id),
        'total_genres', COUNT(DISTINCT g.id),
        'total_duration', COALESCE(SUM(s.length), 0),
        'total_size', COALESCE(SUM(s.size), 0),
        'library_name', l.name
    ) INTO result
    FROM libraries l
    LEFT JOIN albums al ON l.id = al.library_id
    LEFT JOIN songs s ON al.id = s.album_id
    LEFT JOIN artist_song ast ON s.id = ast.song_id
    LEFT JOIN artists art ON ast.artist_id = art.id
    LEFT JOIN genre_song gs ON s.id = gs.song_id
    LEFT JOIN genres g ON gs.genre_id = g.id
    WHERE l.id = input_library_id
    GROUP BY l.id, l.name;
    
    RETURN result;
END;
            ",
            options: [
                'parallel'   => 'restricted',
                'volatility' => 'stable',
            ],
        );

        // Create function to search songs by genre
        Schema::createFunctionOrReplace(
            name: 'search_songs_by_genre',
            parameters: [
                'input_genre_names' => 'text[]',  // Changed parameter name
                'limit_count'       => 'integer',
            ],
            return: [
                'song_id'        => 'bigint',
                'song_public_id' => 'varchar',
                'title'          => 'varchar',
                'artist_names'   => 'text',
                'album_title'    => 'varchar',
                'genre_names'    => 'text',  // Return column name stays the same
            ],
            language: 'plpgsql',
            body: "
BEGIN
    RETURN QUERY
    SELECT 
        s.id as song_id,
        s.public_id as song_public_id,
        s.title,
        STRING_AGG(DISTINCT art.name, ', ') as artist_names,
        al.title as album_title,
        STRING_AGG(DISTINCT g.name, ', ') as genre_names
    FROM songs s
    LEFT JOIN albums al ON s.album_id = al.id
    LEFT JOIN artist_song ast ON s.id = ast.song_id
    LEFT JOIN artists art ON ast.artist_id = art.id
    LEFT JOIN genre_song gs ON s.id = gs.song_id
    LEFT JOIN genres g ON gs.genre_id = g.id
    WHERE g.name = ANY(input_genre_names)  -- Use the new parameter name
    GROUP BY s.id, s.public_id, s.title, al.title
    ORDER BY s.title
    LIMIT limit_count;
END;
            ",
            options: [
                'parallel'   => 'safe',
                'volatility' => 'stable',
            ],
        );

        // Create function to search songs by duration range
        Schema::createFunctionOrReplace(
            name: 'search_songs_by_duration',
            parameters: [
                'min_duration' => 'integer',
                'max_duration' => 'integer',
            ],
            return: [
                'id'           => 'bigint',
                'public_id'    => 'varchar',
                'title'        => 'varchar',
                'length'       => 'integer',
                'artist_names' => 'text',
            ],
            language: 'plpgsql',
            body: "
BEGIN
    RETURN QUERY
    SELECT 
        s.id,
        s.public_id,
        s.title,
        s.length,
        STRING_AGG(DISTINCT art.name, ', ') as artist_names
    FROM songs s
    LEFT JOIN artist_song ast ON s.id = ast.song_id
    LEFT JOIN artists art ON ast.artist_id = art.id
    WHERE s.length BETWEEN min_duration AND max_duration
    GROUP BY s.id, s.public_id, s.title, s.length
    ORDER BY s.length DESC;
END;
            ",
            options: [
                'parallel'   => 'safe',
                'volatility' => 'stable',
            ],
        );

        Schema::createFunctionOrReplace(
            name: 'search_songs_by_content',
            parameters: [
                'genre_ids'       => 'integer[]',
                'artist_ids'      => 'integer[]',
                'target_duration' => 'integer',
                'target_year'     => 'integer',
                'limit_count'     => 'integer',
            ],
            return: [
                'song_id'             => 'bigint',
                'song_public_id'      => 'varchar',
                'title'               => 'varchar',
                'similarity_score'    => 'real',
                'genre_matches'       => 'integer',
                'artist_matches'      => 'integer',
                'duration_similarity' => 'real',
                'year_proximity'      => 'integer',
            ],
            language: 'plpgsql',
            body: "
DECLARE
    duration_tolerance INTEGER := 30; -- seconds
    year_tolerance INTEGER := 3; -- years
BEGIN
    RETURN QUERY
    SELECT 
        s.id as song_id,
        s.public_id as song_public_id,
        s.title,
        (
            COALESCE(genre_match_count.matches, 0) * 0.5 +
            COALESCE(artist_match_count.matches, 0) * 0.3 +
            COALESCE(duration_score.score, 0) * 0.1 +
            COALESCE(year_score.score, 0) * 0.1
        ) as similarity_score,
        COALESCE(genre_match_count.matches, 0) as genre_matches,
        COALESCE(artist_match_count.matches, 0) as artist_matches,
        COALESCE(duration_score.score, 0) as duration_similarity,
        COALESCE(ABS(s.year - target_year), 999) as year_proximity
    FROM songs s
    LEFT JOIN (
        SELECT 
            gs.song_id,
            COUNT(*) as matches
        FROM genre_song gs
        WHERE gs.genre_id = ANY(genre_ids)
        GROUP BY gs.song_id
    ) genre_match_count ON s.id = genre_match_count.song_id
    LEFT JOIN (
        SELECT 
            ars.song_id,
            COUNT(*) as matches
        FROM artist_song ars
        WHERE ars.artist_id = ANY(artist_ids)
        GROUP BY ars.song_id
    ) artist_match_count ON s.id = artist_match_count.song_id
    LEFT JOIN (
        SELECT 
            s2.id as song_id,
            CASE 
                WHEN target_duration IS NULL THEN 0
                WHEN ABS(s2.length - target_duration) <= duration_tolerance 
                THEN 1.0 - (ABS(s2.length - target_duration)::real / duration_tolerance)
                ELSE 0
            END as score
        FROM songs s2
    ) duration_score ON s.id = duration_score.song_id
    LEFT JOIN (
        SELECT 
            s3.id as song_id,
            CASE 
                WHEN target_year IS NULL THEN 0
                WHEN ABS(s3.year - target_year) <= year_tolerance 
                THEN 1.0 - (ABS(s3.year - target_year)::real / year_tolerance)
                ELSE 0
            END as score
        FROM songs s3
    ) year_score ON s.id = year_score.song_id
    WHERE (
        genre_match_count.matches > 0 OR
        artist_match_count.matches > 0 OR
        (target_duration IS NOT NULL AND ABS(s.length - target_duration) <= duration_tolerance) OR
        (target_year IS NOT NULL AND ABS(s.year - target_year) <= year_tolerance)
    )
    ORDER BY similarity_score DESC
    LIMIT limit_count;
END;
            ",
            options: [
                'parallel'   => 'safe',
                'volatility' => 'stable',
            ],
        );

        // Create function to find songs by genre clustering
        Schema::createFunctionOrReplace(
            name: 'find_songs_by_genre_cluster',
            parameters: [
                'song_id'     => 'bigint',
                'limit_count' => 'integer',
            ],
            return: [
                'similar_song_id'        => 'bigint',
                'similar_song_title'     => 'varchar',
                'shared_genres'          => 'text',
                'genre_similarity_score' => 'real',
            ],
            language: 'plpgsql',
            body: "
BEGIN
    RETURN QUERY
    WITH song_genres AS (
        SELECT g.id, g.name
        FROM genres g
        JOIN genre_song gs ON g.id = gs.genre_id
        WHERE gs.song_id = find_songs_by_genre_cluster.song_id
    ),
    similar_songs AS (
        SELECT 
            s.id,
            s.title,
            COUNT(DISTINCT sg.id) as shared_genre_count,
            STRING_AGG(DISTINCT sg.name, ', ') as shared_genre_names,
            COUNT(DISTINCT sg.id)::real / (
                SELECT COUNT(*) FROM song_genres
            ) as similarity_score
        FROM songs s
        JOIN genre_song gs ON s.id = gs.song_id
        JOIN song_genres sg ON gs.genre_id = sg.id
        WHERE s.id != find_songs_by_genre_cluster.song_id
        GROUP BY s.id, s.title
        HAVING COUNT(DISTINCT sg.id) > 0
    )
    SELECT 
        ss.id,
        ss.title,
        ss.shared_genre_names,
        ss.similarity_score
    FROM similar_songs ss
    ORDER BY ss.similarity_score DESC, ss.shared_genre_count DESC
    LIMIT limit_count;
END;
            ",
            options: [
                'parallel'   => 'safe',
                'volatility' => 'stable',
            ],
        );
    }

    public function down(): void
    {
        Schema::dropFunction('search_songs_by_duration');
        Schema::dropFunction('search_songs_by_genre');
        Schema::dropFunction('get_library_stats');
        Schema::dropFunction('search_media_comprehensive');
        Schema::dropFunction('search_albums_similarity');
        Schema::dropFunction('search_artists_similarity');
        Schema::dropFunction('search_songs_similarity');
        Schema::dropFunction('generate_media_id');
        Schema::dropFunction('find_songs_by_genre_cluster');
        Schema::dropFunction('search_songs_by_content');
    }
};
