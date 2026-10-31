<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261030210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Capture query changes transactionally and retain durable scoped revisions.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SET LOCAL lock_timeout = '250ms'");
        $this->addSql(<<<'SQL'
            CREATE TABLE query_change_inbox (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                family text NOT NULL,
                scope_kind text NOT NULL,
                scope_id text NOT NULL,
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                CHECK (scope_kind IN ('user', 'library', 'playlist', 'admin', 'global')),
                CHECK (scope_id <> '')
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE query_scope_revision (
                family text NOT NULL,
                scope_kind text NOT NULL,
                scope_id text NOT NULL,
                revision bigint NOT NULL CHECK (revision > 0),
                PRIMARY KEY (family, scope_kind, scope_id),
                CHECK (scope_kind IN ('user', 'library', 'playlist', 'admin', 'global')),
                CHECK (scope_id <> '')
            )
            SQL);
        $this->addSql('CREATE INDEX idx_query_change_inbox_created_at ON query_change_inbox (created_at)');
        $this->addSql(<<<'SQL'
            CREATE FUNCTION query_live_libraries(route text, row_data jsonb)
            RETURNS TABLE(scope_id text) LANGUAGE plpgsql STABLE AS $$
            BEGIN
                CASE route
                WHEN 'library' THEN RETURN QUERY SELECT row_data->>'library_id';
                WHEN 'library-self' THEN RETURN QUERY SELECT row_data->>'id';
                WHEN 'album' THEN RETURN QUERY SELECT a.library_id::text FROM albums a WHERE a.id = nullif(row_data->>'album_id','')::uuid;
                WHEN 'song' THEN RETURN QUERY SELECT a.library_id::text FROM songs s JOIN albums a ON a.id = s.album_id WHERE s.id = nullif(row_data->>'song_id','')::uuid;
                WHEN 'movie' THEN RETURN QUERY SELECT m.library_id::text FROM movies m WHERE m.id = nullif(row_data->>'movie_id','')::uuid;
                WHEN 'artist' THEN RETURN QUERY
                    SELECT a.library_id::text FROM artist_album aa JOIN albums a ON a.id=aa.album_id WHERE aa.artist_id = nullif(row_data->>'id','')::uuid
                    UNION SELECT a.library_id::text FROM artist_song ar JOIN songs s ON s.id=ar.song_id JOIN albums a ON a.id=s.album_id WHERE ar.artist_id = nullif(row_data->>'id','')::uuid;
                WHEN 'genre' THEN RETURN QUERY
                    SELECT a.library_id::text FROM genre_album ga JOIN albums a ON a.id=ga.album_id WHERE ga.genre_id = nullif(row_data->>'id','')::uuid
                    UNION SELECT a.library_id::text FROM genre_song gs JOIN songs s ON s.id=gs.song_id JOIN albums a ON a.id=s.album_id WHERE gs.genre_id = nullif(row_data->>'id','')::uuid
                    UNION SELECT m.library_id::text FROM genre_movie gm JOIN movies m ON m.id=gm.movie_id WHERE gm.genre_id = nullif(row_data->>'id','')::uuid;
                WHEN 'collection' THEN RETURN QUERY SELECT m.library_id::text FROM movies m WHERE m.tmdb_collection_id=nullif(row_data->>'tmdb_collection_id','')::integer;
                WHEN 'video' THEN RETURN QUERY SELECT m.library_id::text FROM movie_video mv JOIN movies m ON m.id=mv.movie_id WHERE mv.video_id = nullif(row_data->>'id','')::uuid;
                WHEN 'image' THEN RETURN QUERY
                    SELECT a.library_id::text FROM albums a WHERE a.id = nullif(row_data->>'album_id','')::uuid OR a.cover_image_id = nullif(row_data->>'id','')::uuid
                    UNION SELECT q.scope_id FROM query_live_libraries('artist',jsonb_build_object('id',row_data->>'artist_id')) q
                    UNION SELECT q.scope_id FROM artists ar CROSS JOIN LATERAL query_live_libraries('artist',jsonb_build_object('id',ar.id)) q WHERE ar.cover_image_id = nullif(row_data->>'id','')::uuid;
                ELSE NULL;
                END CASE;
            END;
            $$
            SQL);
        $this->addSql(<<<'SQL'
            CREATE FUNCTION query_live_entity_libraries(entity_type text, entity_id text)
            RETURNS TABLE(scope_id text) LANGUAGE sql STABLE AS $$
                SELECT q.scope_id FROM query_live_libraries(
                    CASE entity_type WHEN 'song' THEN 'song' WHEN 'album' THEN 'album' WHEN 'artist' THEN 'artist' WHEN 'movie' THEN 'movie' WHEN 'video' THEN 'video' END,
                    jsonb_build_object('id',entity_id,'song_id',entity_id,'album_id',entity_id,'movie_id',entity_id)) q
            $$
            SQL);
        $this->addSql(<<<'SQL'
            CREATE FUNCTION query_live_recommendation_readers(source_type text, source_id text, target_type text, target_id text)
            RETURNS TABLE(scope_id text) LANGUAGE sql STABLE AS $$
                SELECT u.id::text FROM users u
                WHERE u.roles ?| ARRAY['ROLE_ADMIN', 'ROLE_SUPER_ADMIN']
                   OR (EXISTS(SELECT 1 FROM query_live_entity_libraries(source_type,source_id) q JOIN user_library_access a ON a.library_id=q.scope_id::uuid WHERE a.user_id=u.id)
                   AND EXISTS(SELECT 1 FROM query_live_entity_libraries(target_type,target_id) q JOIN user_library_access a ON a.library_id=q.scope_id::uuid WHERE a.user_id=u.id))
            $$
            SQL);
        $this->addSql(<<<'SQL'
            CREATE FUNCTION query_live_capture(source_table text, row_data jsonb, families text[], routes text[])
            RETURNS TABLE(family text, scope_kind text, scope_id text) LANGUAGE plpgsql AS $$
            DECLARE
                library_ids text[];
                playlist_ids text[];
                user_ids text[];
                access_token_ids uuid[] := ARRAY[]::uuid[];
                song_ids text[];
                album_ids text[];
                artist_ids text[];
                movie_ids text[];
                family_name text;
            BEGIN
                IF 'token'=ANY(routes) THEN
                    access_token_ids:=array_append(access_token_ids,nullif(row_data->>'token_id','')::uuid);
                END IF;
                IF 'refresh-token'=ANY(routes) THEN
                    access_token_ids:=array_append(access_token_ids,nullif(row_data->>'access_token_id','')::uuid);
                END IF;
                SELECT coalesce(array_agg(DISTINCT q.scope_id) FILTER (WHERE q.scope_id IS NOT NULL), ARRAY[]::text[])
                INTO library_ids FROM unnest(routes) r CROSS JOIN LATERAL query_live_libraries(r,row_data) q;
                SELECT coalesce(array_agg(DISTINCT q.id) FILTER (WHERE q.id IS NOT NULL), ARRAY[]::text[]) INTO playlist_ids
                FROM (
                    SELECT row_data->>'playlist_id' id WHERE 'playlist'=ANY(routes) OR 'image'=ANY(routes)
                    UNION SELECT row_data->>'id' WHERE 'playlist-self'=ANY(routes)
                ) q;
                SELECT coalesce(array_agg(DISTINCT q.id) FILTER (WHERE q.id IS NOT NULL), ARRAY[]::text[]) INTO user_ids
                FROM (
                    SELECT row_data->>'user_id' id WHERE 'user'=ANY(routes) OR 'recommendation'=ANY(routes)
                    UNION SELECT row_data->>'id' WHERE 'self'=ANY(routes)
                    UNION SELECT row_data->>'owner_user_id' WHERE 'transcode-owner'=ANY(routes)
                    UNION SELECT t.user_id::text FROM oauth_access_tokens t WHERE t.id=ANY(access_token_ids)
                    UNION SELECT p.user_id::text FROM playlists p WHERE p.id=ANY(playlist_ids::uuid[])
                    UNION SELECT c.user_id::text FROM playlist_collaborators c WHERE c.playlist_id=ANY(playlist_ids::uuid[])
                    UNION SELECT row_data->>'user_id' WHERE source_table='playlists'
                ) q;
            
                song_ids:=ARRAY[]::text[];
                album_ids:=ARRAY[]::text[];
                artist_ids:=ARRAY[]::text[];
                movie_ids:=ARRAY[]::text[];
                -- Resolve dependent owners while the old relationships are still present.
                IF cardinality(library_ids)>0 OR source_table IN ('songs','albums','artists','movies') THEN
                SELECT coalesce(array_agg(DISTINCT s.id::text), ARRAY[]::text[]) INTO song_ids FROM songs s
                WHERE (source_table='songs' AND s.id = nullif(row_data->>'id','')::uuid)
                   OR (source_table IN ('albums','artist_album','genre_album') AND s.album_id=coalesce(row_data->>'album_id',row_data->>'id')::uuid)
                   OR (source_table IN ('artist_song','genre_song','lyrics') AND s.id = nullif(row_data->>'song_id','')::uuid)
                   OR (source_table='artists' AND (EXISTS(SELECT 1 FROM artist_song ar WHERE ar.song_id=s.id AND ar.artist_id = nullif(row_data->>'id','')::uuid) OR EXISTS(SELECT 1 FROM artist_album ar WHERE ar.album_id=s.album_id AND ar.artist_id = nullif(row_data->>'id','')::uuid)))
                   OR (source_table='genres' AND (EXISTS(SELECT 1 FROM genre_song g WHERE g.song_id=s.id AND g.genre_id = nullif(row_data->>'id','')::uuid) OR EXISTS(SELECT 1 FROM genre_album g WHERE g.album_id=s.album_id AND g.genre_id = nullif(row_data->>'id','')::uuid)))
                   OR (source_table='images' AND (s.album_id = nullif(row_data->>'album_id','')::uuid OR EXISTS(SELECT 1 FROM albums a WHERE a.id=s.album_id AND a.cover_image_id = nullif(row_data->>'id','')::uuid) OR EXISTS(SELECT 1 FROM artist_song ar WHERE ar.song_id=s.id AND (ar.artist_id = nullif(row_data->>'artist_id','')::uuid OR EXISTS(SELECT 1 FROM artists a WHERE a.id=ar.artist_id AND a.cover_image_id = nullif(row_data->>'id','')::uuid))) OR EXISTS(SELECT 1 FROM artist_album ar WHERE ar.album_id=s.album_id AND (ar.artist_id = nullif(row_data->>'artist_id','')::uuid OR EXISTS(SELECT 1 FROM artists a WHERE a.id=ar.artist_id AND a.cover_image_id = nullif(row_data->>'id','')::uuid)))))
                   OR (source_table='library_file_index' AND s.path=row_data->>'path' AND EXISTS(SELECT 1 FROM albums a WHERE a.id=s.album_id AND a.library_id = nullif(row_data->>'library_id','')::uuid))
                   OR (source_table='libraries' AND EXISTS(SELECT 1 FROM albums a WHERE a.id=s.album_id AND a.library_id = nullif(row_data->>'id','')::uuid));
                IF source_table='songs' THEN song_ids:=array_append(song_ids,row_data->>'id'); END IF;
                SELECT coalesce(array_agg(DISTINCT q.id), ARRAY[]::text[]) INTO album_ids FROM (
                    SELECT s.album_id::text id FROM songs s WHERE s.id=ANY(song_ids::uuid[])
                    UNION SELECT row_data->>'id' WHERE source_table='albums'
                    UNION SELECT row_data->>'album_id' WHERE source_table IN ('songs','artist_album','genre_album','images') AND row_data->>'album_id' IS NOT NULL
                ) q;
                SELECT coalesce(array_agg(DISTINCT q.id), ARRAY[]::text[]) INTO artist_ids FROM (
                    SELECT ar.artist_id::text id FROM artist_song ar WHERE ar.song_id=ANY(song_ids::uuid[])
                    UNION SELECT ar.artist_id::text FROM artist_album ar WHERE ar.album_id=ANY(album_ids::uuid[])
                    UNION SELECT row_data->>'id' WHERE source_table='artists'
                    UNION SELECT row_data->>'artist_id' WHERE source_table IN ('artist_song','artist_album','images') AND row_data->>'artist_id' IS NOT NULL
                ) q;
                SELECT coalesce(array_agg(DISTINCT m.id::text), ARRAY[]::text[]) INTO movie_ids FROM movies m
                WHERE (source_table='movies' AND m.id = nullif(row_data->>'id','')::uuid)
                   OR (source_table IN ('movie_video','genre_movie') AND m.id = nullif(row_data->>'movie_id','')::uuid)
                   OR (source_table='videos' AND EXISTS(SELECT 1 FROM movie_video mv WHERE mv.movie_id=m.id AND mv.video_id = nullif(row_data->>'id','')::uuid))
                   OR (source_table='movie_collections' AND m.tmdb_collection_id=nullif(row_data->>'tmdb_collection_id','')::integer)
                   OR (source_table='genres' AND EXISTS(SELECT 1 FROM genre_movie gm WHERE gm.movie_id=m.id AND gm.genre_id = nullif(row_data->>'id','')::uuid))
                   OR (source_table='libraries' AND m.library_id = nullif(row_data->>'id','')::uuid);
                IF source_table='movies' THEN movie_ids:=array_append(movie_ids,row_data->>'id'); END IF;
            
                END IF;
            
                FOREACH family_name IN ARRAY families LOOP
                    IF family_name LIKE 'admin-%' THEN
                        RETURN QUERY SELECT family_name,'admin'::text,'*'::text;
                    ELSE
                        RETURN QUERY SELECT DISTINCT family_name,q.kind,q.id FROM (
                            SELECT 'user' kind,unnest(user_ids) id
                            UNION SELECT 'library',unnest(library_ids) WHERE family_name IN ('catalog','libraries','lyrics')
                            UNION SELECT 'playlist',unnest(playlist_ids) WHERE family_name IN ('catalog','playlists')
                            UNION SELECT 'global','*' WHERE 'public'=ANY(routes)
                            UNION SELECT 'user',a.user_id::text FROM media_activities a WHERE family_name='activity' AND (a.song_id=ANY(song_ids::uuid[]) OR a.album_id=ANY(album_ids::uuid[]) OR a.artist_id=ANY(artist_ids::uuid[]) OR a.movie_id=ANY(movie_ids::uuid[]))
                            UNION SELECT 'playlist',ps.playlist_id::text FROM playlist_song ps WHERE family_name='playlists' AND ps.song_id=ANY(song_ids::uuid[])
                            UNION SELECT 'user',p.user_id::text FROM playlists p JOIN playlist_song ps ON ps.playlist_id=p.id WHERE family_name='playlists' AND ps.song_id=ANY(song_ids::uuid[])
                            UNION SELECT 'user',c.user_id::text FROM playlist_collaborators c JOIN playlist_song ps ON ps.playlist_id=c.playlist_id WHERE family_name='playlists' AND ps.song_id=ANY(song_ids::uuid[])
                            UNION SELECT 'user',r.user_id::text FROM recommendations r WHERE family_name='recommendations' AND r.user_id IS NOT NULL AND (
                                (r.source_type='song' AND r.source_id=ANY(song_ids)) OR (r.target_type='song' AND r.target_id=ANY(song_ids))
                                OR (r.source_type='album' AND r.source_id=ANY(album_ids)) OR (r.target_type='album' AND r.target_id=ANY(album_ids))
                                OR (r.source_type='artist' AND r.source_id=ANY(artist_ids)) OR (r.target_type='artist' AND r.target_id=ANY(artist_ids))
                                OR (r.source_type='movie' AND r.source_id=ANY(movie_ids)) OR (r.target_type='movie' AND r.target_id=ANY(movie_ids)))
                            UNION SELECT 'user',a.user_id::text FROM media_activities a WHERE family_name='recommendations' AND a.song_id=ANY(song_ids::uuid[])
                            UNION SELECT 'user',reader.scope_id FROM recommendations r CROSS JOIN LATERAL query_live_recommendation_readers(r.source_type,r.source_id,r.target_type,r.target_id) reader WHERE family_name='recommendations' AND r.user_id IS NULL AND (
                                (r.source_type='song' AND r.source_id=ANY(song_ids)) OR (r.target_type='song' AND r.target_id=ANY(song_ids))
                                OR (r.source_type='album' AND r.source_id=ANY(album_ids)) OR (r.target_type='album' AND r.target_id=ANY(album_ids))
                                OR (r.source_type='artist' AND r.source_id=ANY(artist_ids)) OR (r.target_type='artist' AND r.target_id=ANY(artist_ids))
                                OR (r.source_type='movie' AND r.source_id=ANY(movie_ids)) OR (r.target_type='movie' AND r.target_id=ANY(movie_ids)))
                            UNION SELECT 'user',reader.scope_id FROM query_live_recommendation_readers(row_data->>'source_type',row_data->>'source_id',row_data->>'target_type',row_data->>'target_id') reader WHERE family_name='recommendations' AND source_table='recommendations' AND row_data->>'user_id' IS NULL
                            UNION SELECT 'user',u.id::text FROM users u WHERE family_name='recommendations' AND source_table IN ('recommendation_jobs','system_settings') AND row_data->>'user_id' IS NULL
                                AND (u.roles ?| ARRAY['ROLE_ADMIN', 'ROLE_SUPER_ADMIN'] OR EXISTS(SELECT 1 FROM user_library_access a WHERE a.user_id=u.id) OR EXISTS(SELECT 1 FROM recommendations r WHERE r.user_id=u.id))
                        ) q WHERE q.id IS NOT NULL;
                    END IF;
                END LOOP;
            END;
            $$
            SQL);
        $this->addSql(<<<'SQL'
            CREATE FUNCTION query_live_capture_row() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                old_data jsonb;
                new_data jsonb;
                relevant text[] := TG_ARGV[0]::text[];
            BEGIN
                IF TG_OP<>'INSERT' THEN old_data:=to_jsonb(OLD); END IF;
                IF TG_OP<>'DELETE' THEN new_data:=to_jsonb(NEW); END IF;
                IF TG_OP='UPDATE' AND NOT EXISTS (SELECT 1 FROM unnest(relevant) c WHERE old_data->c IS DISTINCT FROM new_data->c) THEN
                    IF TG_TABLE_NAME='users' AND ((old_data->>'totp_secret' IS NULL) IS DISTINCT FROM (new_data->>'totp_secret' IS NULL)) THEN
                        INSERT INTO query_change_inbox(family,scope_kind,scope_id)
                        SELECT DISTINCT 'account','user',principal
                        FROM (VALUES (old_data->>'id'),(new_data->>'id')) recipients(principal)
                        WHERE principal IS NOT NULL;
                    END IF;
                    RETURN NEW;
                END IF;
                INSERT INTO query_change_inbox(family,scope_kind,scope_id)
                SELECT DISTINCT resolved.family,resolved.scope_kind,resolved.scope_id
                FROM (SELECT old_data row_data WHERE old_data IS NOT NULL UNION SELECT new_data WHERE new_data IS NOT NULL) images
                CROSS JOIN LATERAL query_live_capture(TG_TABLE_NAME,images.row_data,TG_ARGV[1]::text[],TG_ARGV[2]::text[]) resolved;
                IF TG_OP='DELETE' THEN RETURN OLD; END IF;
                RETURN NEW;
            END;
            $$
            SQL);
        $this->addSql(<<<'SQL'
            CREATE FUNCTION query_live_capture_users_statement() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                principal text;
                family_name text;
                query_text text;
            BEGIN
                IF TG_OP='UPDATE' THEN
                    query_text := 'WITH old_values AS (SELECT (SELECT jsonb_object_agg(k,to_jsonb(o)->k) FROM unnest($1) k) value FROM query_old_rows o), new_values AS (SELECT (SELECT jsonb_object_agg(k,to_jsonb(n)->k) FROM unnest($1) k) value FROM query_new_rows n), changed AS ((SELECT value FROM old_values EXCEPT SELECT value FROM new_values) UNION (SELECT value FROM new_values EXCEPT SELECT value FROM old_values)) SELECT DISTINCT value->>''user_id'' FROM changed';
                ELSIF TG_OP='INSERT' THEN
                    query_text := 'SELECT DISTINCT user_id::text FROM query_new_rows';
                ELSE
                    query_text := 'SELECT DISTINCT user_id::text FROM query_old_rows';
                END IF;
                FOR principal IN EXECUTE query_text USING TG_ARGV[0]::text[] LOOP
                    IF principal IS NOT NULL THEN
                        FOREACH family_name IN ARRAY TG_ARGV[1]::text[] LOOP
                            INSERT INTO query_change_inbox(family,scope_kind,scope_id) VALUES(family_name,'user',principal);
                        END LOOP;
                    END IF;
                END LOOP;
                RETURN NULL;
            END;
            $$
            SQL);
        $this->addSql("CREATE TRIGGER query_capture_before BEFORE UPDATE OR DELETE ON albums FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{id,public_id,library_id,title,type,mbid,discogs_id,spotify_id,year,label,catalog_number,barcode,country,language,disambiguation,annotation,locked_fields,merged_from,cover_image_id,created_at}', '{catalog,libraries,activity,playlists,recommendations,admin-metadata}', '{library}')");
        $this->addSql("CREATE TRIGGER query_capture_insert AFTER INSERT ON albums FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{id,public_id,library_id,title,type,mbid,discogs_id,spotify_id,year,label,catalog_number,barcode,country,language,disambiguation,annotation,locked_fields,merged_from,cover_image_id,created_at}', '{catalog,libraries,activity,playlists,recommendations,admin-metadata}', '{library}')");
        $this->addSql("CREATE TRIGGER query_capture_before BEFORE UPDATE OR DELETE ON artist_album FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{id,artist_id,album_id,role}', '{catalog,libraries,activity,playlists,recommendations,admin-metadata}', '{album}')");
        $this->addSql("CREATE TRIGGER query_capture_insert AFTER INSERT ON artist_album FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{id,artist_id,album_id,role}', '{catalog,libraries,activity,playlists,recommendations,admin-metadata}', '{album}')");
        $this->addSql("CREATE TRIGGER query_capture_before BEFORE UPDATE OR DELETE ON artist_song FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{id,artist_id,song_id,role}', '{catalog,libraries,activity,playlists,recommendations,admin-metadata}', '{song}')");
        $this->addSql("CREATE TRIGGER query_capture_insert AFTER INSERT ON artist_song FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{id,artist_id,song_id,role}', '{catalog,libraries,activity,playlists,recommendations,admin-metadata}', '{song}')");
        $this->addSql("CREATE TRIGGER query_capture_before BEFORE UPDATE OR DELETE ON artists FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{cover_image_id,id,public_id,name,country,gender,type,life_span_begin,life_span_end,disambiguation,sort_name,biography,mbid,discogs_id,spotify_id,locked_fields,created_at}', '{catalog,libraries,activity,playlists,recommendations,admin-metadata}', '{artist}')");
        $this->addSql("CREATE TRIGGER query_capture_insert AFTER INSERT ON artists FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{cover_image_id,id,public_id,name,country,gender,type,life_span_begin,life_span_end,disambiguation,sort_name,biography,mbid,discogs_id,spotify_id,locked_fields,created_at}', '{catalog,libraries,activity,playlists,recommendations,admin-metadata}', '{artist}')");
        $this->addSql("CREATE TRIGGER query_capture_insert AFTER INSERT ON audio_preferences REFERENCING NEW TABLE AS query_new_rows FOR EACH STATEMENT EXECUTE FUNCTION query_live_capture_users_statement('{id,user_id,payload,version,created_at}', '{preferences}', '{user}')");
        $this->addSql("CREATE TRIGGER query_capture_update AFTER UPDATE ON audio_preferences REFERENCING OLD TABLE AS query_old_rows NEW TABLE AS query_new_rows FOR EACH STATEMENT EXECUTE FUNCTION query_live_capture_users_statement('{id,user_id,payload,version,created_at}', '{preferences}', '{user}')");
        $this->addSql("CREATE TRIGGER query_capture_delete AFTER DELETE ON audio_preferences REFERENCING OLD TABLE AS query_old_rows FOR EACH STATEMENT EXECUTE FUNCTION query_live_capture_users_statement('{id,user_id,payload,version,created_at}', '{preferences}', '{user}')");
        $this->addSql("CREATE TRIGGER query_capture_before BEFORE UPDATE OR DELETE ON audio_rendition_runtime FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{rendition_key,track_key,source_signature,source_path,format,bitrate,output_directory,state,generation,partial_path,final_path,error}', '{admin-media}', '{admin}')");
        $this->addSql("CREATE TRIGGER query_capture_insert AFTER INSERT ON audio_rendition_runtime FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{rendition_key,track_key,source_signature,source_path,format,bitrate,output_directory,state,generation,partial_path,final_path,error}', '{admin-media}', '{admin}')");
        $this->addSql("CREATE TRIGGER query_capture_insert AFTER INSERT ON country_subscriptions REFERENCING NEW TABLE AS query_new_rows FOR EACH STATEMENT EXECUTE FUNCTION query_live_capture_users_statement('{id,public_id,user_id,source_id,country_code,last_synced_at,created_at}', '{radio}', '{user}')");
        $this->addSql("CREATE TRIGGER query_capture_update AFTER UPDATE ON country_subscriptions REFERENCING OLD TABLE AS query_old_rows NEW TABLE AS query_new_rows FOR EACH STATEMENT EXECUTE FUNCTION query_live_capture_users_statement('{id,public_id,user_id,source_id,country_code,last_synced_at,created_at}', '{radio}', '{user}')");
        $this->addSql("CREATE TRIGGER query_capture_delete AFTER DELETE ON country_subscriptions REFERENCING OLD TABLE AS query_old_rows FOR EACH STATEMENT EXECUTE FUNCTION query_live_capture_users_statement('{id,public_id,user_id,source_id,country_code,last_synced_at,created_at}', '{radio}', '{user}')");
        $this->addSql("CREATE TRIGGER query_capture_insert AFTER INSERT ON devices REFERENCING NEW TABLE AS query_new_rows FOR EACH STATEMENT EXECUTE FUNCTION query_live_capture_users_statement('{id,public_id,user_id,device_id,name,created_at}', '{devices,sessions}', '{user}')");
        $this->addSql("CREATE TRIGGER query_capture_update AFTER UPDATE ON devices REFERENCING OLD TABLE AS query_old_rows NEW TABLE AS query_new_rows FOR EACH STATEMENT EXECUTE FUNCTION query_live_capture_users_statement('{id,public_id,user_id,device_id,name,created_at}', '{devices,sessions}', '{user}')");
        $this->addSql("CREATE TRIGGER query_capture_delete AFTER DELETE ON devices REFERENCING OLD TABLE AS query_old_rows FOR EACH STATEMENT EXECUTE FUNCTION query_live_capture_users_statement('{id,public_id,user_id,device_id,name,created_at}', '{devices,sessions}', '{user}')");
        $this->addSql("CREATE TRIGGER query_capture_insert AFTER INSERT ON eq_device_profiles REFERENCING NEW TABLE AS query_new_rows FOR EACH STATEMENT EXECUTE FUNCTION query_live_capture_users_statement('{id,public_id,user_id,name,icon,device_id,payload,is_default,sort_order,version,created_at}', '{preferences}', '{user}')");
        $this->addSql("CREATE TRIGGER query_capture_update AFTER UPDATE ON eq_device_profiles REFERENCING OLD TABLE AS query_old_rows NEW TABLE AS query_new_rows FOR EACH STATEMENT EXECUTE FUNCTION query_live_capture_users_statement('{id,public_id,user_id,name,icon,device_id,payload,is_default,sort_order,version,created_at}', '{preferences}', '{user}')");
        $this->addSql("CREATE TRIGGER query_capture_delete AFTER DELETE ON eq_device_profiles REFERENCING OLD TABLE AS query_old_rows FOR EACH STATEMENT EXECUTE FUNCTION query_live_capture_users_statement('{id,public_id,user_id,name,icon,device_id,payload,is_default,sort_order,version,created_at}', '{preferences}', '{user}')");
        $this->addSql("CREATE TRIGGER query_capture_before BEFORE UPDATE OR DELETE ON failed_messages FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{id,body,headers,queue_name,created_at,available_at,delivered_at}', '{admin-jobs}', '{admin}')");
        $this->addSql("CREATE TRIGGER query_capture_insert AFTER INSERT ON failed_messages FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{id,body,headers,queue_name,created_at,available_at,delivered_at}', '{admin-jobs}', '{admin}')");
        $this->addSql("CREATE TRIGGER query_capture_before BEFORE UPDATE OR DELETE ON genre_album FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{id,genre_id,album_id}', '{catalog,libraries,activity,playlists,recommendations,admin-metadata}', '{album}')");
        $this->addSql("CREATE TRIGGER query_capture_insert AFTER INSERT ON genre_album FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{id,genre_id,album_id}', '{catalog,libraries,activity,playlists,recommendations,admin-metadata}', '{album}')");
        $this->addSql("CREATE TRIGGER query_capture_before BEFORE UPDATE OR DELETE ON genre_movie FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{id,genre_id,movie_id}', '{catalog,libraries,activity,recommendations,admin-media,admin-metadata}', '{movie}')");
        $this->addSql("CREATE TRIGGER query_capture_insert AFTER INSERT ON genre_movie FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{id,genre_id,movie_id}', '{catalog,libraries,activity,recommendations,admin-media,admin-metadata}', '{movie}')");
        $this->addSql("CREATE TRIGGER query_capture_before BEFORE UPDATE OR DELETE ON genre_song FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{id,genre_id,song_id,position}', '{catalog,libraries,activity,playlists,recommendations,admin-metadata}', '{song}')");
        $this->addSql("CREATE TRIGGER query_capture_insert AFTER INSERT ON genre_song FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{id,genre_id,song_id,position}', '{catalog,libraries,activity,playlists,recommendations,admin-metadata}', '{song}')");
        $this->addSql("CREATE TRIGGER query_capture_before BEFORE UPDATE OR DELETE ON genres FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{id,public_id,parent_id,name,slug,mbid,created_at}', '{catalog,libraries,activity,playlists,recommendations,admin-metadata}', '{genre}')");
        $this->addSql("CREATE TRIGGER query_capture_insert AFTER INSERT ON genres FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{id,public_id,parent_id,name,slug,mbid,created_at}', '{catalog,libraries,activity,playlists,recommendations,admin-metadata}', '{genre}')");
        $this->addSql("CREATE TRIGGER query_capture_before BEFORE UPDATE OR DELETE ON images FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{id,path,extension,mime_type,blurhash,public_id,size,width,height,imageable_type,album_id,artist_id,playlist_id,created_at}', '{catalog,playlists,activity,admin-media}', '{image}')");
        $this->addSql("CREATE TRIGGER query_capture_insert AFTER INSERT ON images FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{id,path,extension,mime_type,blurhash,public_id,size,width,height,imageable_type,album_id,artist_id,playlist_id,created_at}', '{catalog,playlists,activity,admin-media}', '{image}')");
        $this->addSql("CREATE TRIGGER query_capture_before BEFORE UPDATE OR DELETE ON job_monitors FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{id,job_uuid,job_id,name,queue,status,queued_at,started_at,finished_at,duration_microseconds,attempt,retried,progress,exception,exception_class,data,data_truncated,audit_log,created_at}', '{admin-jobs}', '{admin}')");
        $this->addSql("CREATE TRIGGER query_capture_insert AFTER INSERT ON job_monitors FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{id,job_uuid,job_id,name,queue,status,queued_at,started_at,finished_at,duration_microseconds,attempt,retried,progress,exception,exception_class,data,data_truncated,audit_log,created_at}', '{admin-jobs}', '{admin}')");
        $this->addSql("CREATE TRIGGER query_capture_insert AFTER INSERT ON layout_preferences REFERENCING NEW TABLE AS query_new_rows FOR EACH STATEMENT EXECUTE FUNCTION query_live_capture_users_statement('{id,user_id,payload,version,created_at}', '{preferences}', '{user}')");
        $this->addSql("CREATE TRIGGER query_capture_update AFTER UPDATE ON layout_preferences REFERENCING OLD TABLE AS query_old_rows NEW TABLE AS query_new_rows FOR EACH STATEMENT EXECUTE FUNCTION query_live_capture_users_statement('{id,user_id,payload,version,created_at}', '{preferences}', '{user}')");
        $this->addSql("CREATE TRIGGER query_capture_delete AFTER DELETE ON layout_preferences REFERENCING OLD TABLE AS query_old_rows FOR EACH STATEMENT EXECUTE FUNCTION query_live_capture_users_statement('{id,user_id,payload,version,created_at}', '{preferences}', '{user}')");
        $this->addSql("CREATE TRIGGER query_capture_before BEFORE UPDATE OR DELETE ON libraries FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{id,public_id,name,slug,path,type,filesystem_type,sort_order,last_scan,created_at,scan_status}', '{libraries,catalog,activity,playlists,recommendations,admin-metadata}', '{library-self,admin}')");
        $this->addSql("CREATE TRIGGER query_capture_insert AFTER INSERT ON libraries FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{id,public_id,name,slug,path,type,filesystem_type,sort_order,last_scan,created_at,scan_status}', '{libraries,catalog,activity,playlists,recommendations,admin-metadata}', '{library-self,admin}')");
        $this->addSql("CREATE TRIGGER query_capture_before BEFORE UPDATE OR DELETE ON library_file_index FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{id,library_id,path,hash,size,extension,modified_at,available}', '{catalog,libraries,activity,admin-media,playlists,recommendations}', '{library}')");
        $this->addSql("CREATE TRIGGER query_capture_insert AFTER INSERT ON library_file_index FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{id,library_id,path,hash,size,extension,modified_at,available}', '{catalog,libraries,activity,admin-media,playlists,recommendations}', '{library}')");
        $this->addSql("CREATE TRIGGER query_capture_insert AFTER INSERT ON listening_sessions REFERENCING NEW TABLE AS query_new_rows FOR EACH STATEMENT EXECUTE FUNCTION query_live_capture_users_statement('{id,public_id,user_id,active_device_id,queue,current_track_index,playback_state,created_at}', '{sessions}', '{user}')");
        $this->addSql("CREATE TRIGGER query_capture_update AFTER UPDATE ON listening_sessions REFERENCING OLD TABLE AS query_old_rows NEW TABLE AS query_new_rows FOR EACH STATEMENT EXECUTE FUNCTION query_live_capture_users_statement('{id,public_id,user_id,active_device_id,queue,current_track_index,playback_state,created_at}', '{sessions}', '{user}')");
        $this->addSql("CREATE TRIGGER query_capture_delete AFTER DELETE ON listening_sessions REFERENCING OLD TABLE AS query_old_rows FOR EACH STATEMENT EXECUTE FUNCTION query_live_capture_users_statement('{id,public_id,user_id,active_device_id,queue,current_track_index,playback_state,created_at}', '{sessions}', '{user}')");
        $this->addSql("CREATE TRIGGER query_capture_before BEFORE UPDATE OR DELETE ON login_blocks FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{id,public_id,ip_address,email,field_value,user_agent,created_at}', '{admin-security}', '{admin}')");
        $this->addSql("CREATE TRIGGER query_capture_insert AFTER INSERT ON login_blocks FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{id,public_id,ip_address,email,field_value,user_agent,created_at}', '{admin-security}', '{admin}')");
        $this->addSql("CREATE TRIGGER query_capture_before BEFORE UPDATE OR DELETE ON lyrics FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{id,song_id,plain_lyrics,synced_lyrics,source,source_url,lrclib_id,is_instrumental,created_at}', '{lyrics,catalog,admin-metadata}', '{song}')");
        $this->addSql("CREATE TRIGGER query_capture_insert AFTER INSERT ON lyrics FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{id,song_id,plain_lyrics,synced_lyrics,source,source_url,lrclib_id,is_instrumental,created_at}', '{lyrics,catalog,admin-metadata}', '{song}')");
        $this->addSql("CREATE TRIGGER query_capture_before BEFORE UPDATE OR DELETE ON media_activities FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{id,public_id,user_id,activity_type,song_id,album_id,artist_id,movie_id,play_count,love,last_played_at,last_platform,last_player,created_at}', '{activity,recommendations,admin-metadata}', '{user}')");
        $this->addSql("CREATE TRIGGER query_capture_insert AFTER INSERT ON media_activities FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{id,public_id,user_id,activity_type,song_id,album_id,artist_id,movie_id,play_count,love,last_played_at,last_platform,last_player,created_at}', '{activity,recommendations,admin-metadata}', '{user}')");
        $this->addSql("CREATE TRIGGER query_capture_before BEFORE UPDATE OR DELETE ON movie_collections FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{id,tmdb_collection_id,name,overview,poster_path,backdrop_path}', '{catalog,libraries,activity,recommendations,admin-media,admin-metadata}', '{collection}')");
        $this->addSql("CREATE TRIGGER query_capture_insert AFTER INSERT ON movie_collections FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{id,tmdb_collection_id,name,overview,poster_path,backdrop_path}', '{catalog,libraries,activity,recommendations,admin-media,admin-metadata}', '{collection}')");
        $this->addSql("CREATE TRIGGER query_capture_before BEFORE UPDATE OR DELETE ON movie_video FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{id,movie_id,video_id,sort_order}', '{catalog,libraries,activity,recommendations,admin-media,admin-metadata}', '{movie}')");
        $this->addSql("CREATE TRIGGER query_capture_insert AFTER INSERT ON movie_video FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{id,movie_id,video_id,sort_order}', '{catalog,libraries,activity,recommendations,admin-media,admin-metadata}', '{movie}')");
        $this->addSql("CREATE TRIGGER query_capture_before BEFORE UPDATE OR DELETE ON movies FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{id,public_id,library_id,title,year,summary,tmdb_id,imdb_id,overview,tagline,poster_url,backdrop_url,runtime,rating,original_language,tmdb_collection_id,collection_name,created_at}', '{catalog,libraries,activity,recommendations,admin-media,admin-metadata}', '{library}')");
        $this->addSql("CREATE TRIGGER query_capture_insert AFTER INSERT ON movies FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{id,public_id,library_id,title,year,summary,tmdb_id,imdb_id,overview,tagline,poster_url,backdrop_url,runtime,rating,original_language,tmdb_collection_id,collection_name,created_at}', '{catalog,libraries,activity,recommendations,admin-media,admin-metadata}', '{library}')");
        $this->addSql("CREATE TRIGGER query_capture_insert AFTER INSERT ON notification_preferences REFERENCING NEW TABLE AS query_new_rows FOR EACH STATEMENT EXECUTE FUNCTION query_live_capture_users_statement('{id,user_id,category,channel,enabled,created_at}', '{notifications}', '{user}')");
        $this->addSql("CREATE TRIGGER query_capture_update AFTER UPDATE ON notification_preferences REFERENCING OLD TABLE AS query_old_rows NEW TABLE AS query_new_rows FOR EACH STATEMENT EXECUTE FUNCTION query_live_capture_users_statement('{id,user_id,category,channel,enabled,created_at}', '{notifications}', '{user}')");
        $this->addSql("CREATE TRIGGER query_capture_delete AFTER DELETE ON notification_preferences REFERENCING OLD TABLE AS query_old_rows FOR EACH STATEMENT EXECUTE FUNCTION query_live_capture_users_statement('{id,user_id,category,channel,enabled,created_at}', '{notifications}', '{user}')");
        $this->addSql("CREATE TRIGGER query_capture_insert AFTER INSERT ON notifications REFERENCING NEW TABLE AS query_new_rows FOR EACH STATEMENT EXECUTE FUNCTION query_live_capture_users_statement('{id,public_id,user_id,category,event_type,title,body,is_read,reference_data,parameters,created_at}', '{notifications}', '{user}')");
        $this->addSql("CREATE TRIGGER query_capture_update AFTER UPDATE ON notifications REFERENCING OLD TABLE AS query_old_rows NEW TABLE AS query_new_rows FOR EACH STATEMENT EXECUTE FUNCTION query_live_capture_users_statement('{id,public_id,user_id,category,event_type,title,body,is_read,reference_data,parameters,created_at}', '{notifications}', '{user}')");
        $this->addSql("CREATE TRIGGER query_capture_delete AFTER DELETE ON notifications REFERENCING OLD TABLE AS query_old_rows FOR EACH STATEMENT EXECUTE FUNCTION query_live_capture_users_statement('{id,public_id,user_id,category,event_type,title,body,is_read,reference_data,parameters,created_at}', '{notifications}', '{user}')");
        $this->addSql("CREATE TRIGGER query_capture_before BEFORE UPDATE OR DELETE ON oauth_access_tokens FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{id,chain_id,user_id,client_id,name,scopes,revoked,expires_at,last_refreshed_at,created_at}', '{account,devices,admin-security}', '{user,admin}')");
        $this->addSql("CREATE TRIGGER query_capture_insert AFTER INSERT ON oauth_access_tokens FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{id,chain_id,user_id,client_id,name,scopes,revoked,expires_at,last_refreshed_at,created_at}', '{account,devices,admin-security}', '{user,admin}')");
        $this->addSql("CREATE TRIGGER query_capture_before BEFORE UPDATE OR DELETE ON oauth_clients FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{id,public_id,name,provider,redirect,personal_access_client,password_client,device_client,confidential,first_party,revoked,user_id,created_at}', '{account,admin-users}', '{user,admin}')");
        $this->addSql("CREATE TRIGGER query_capture_insert AFTER INSERT ON oauth_clients FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{id,public_id,name,provider,redirect,personal_access_client,password_client,device_client,confidential,first_party,revoked,user_id,created_at}', '{account,admin-users}', '{user,admin}')");
        $this->addSql("CREATE TRIGGER query_capture_insert AFTER INSERT ON oauth_device_codes REFERENCING NEW TABLE AS query_new_rows FOR EACH STATEMENT EXECUTE FUNCTION query_live_capture_users_statement('{id,user_id,client_id,scopes,expires_at,interval,approved,denied,consumed_at,created_at}', '{account,devices}', '{user}')");
        $this->addSql("CREATE TRIGGER query_capture_update AFTER UPDATE ON oauth_device_codes REFERENCING OLD TABLE AS query_old_rows NEW TABLE AS query_new_rows FOR EACH STATEMENT EXECUTE FUNCTION query_live_capture_users_statement('{id,user_id,client_id,scopes,expires_at,interval,approved,denied,consumed_at,created_at}', '{account,devices}', '{user}')");
        $this->addSql("CREATE TRIGGER query_capture_delete AFTER DELETE ON oauth_device_codes REFERENCING OLD TABLE AS query_old_rows FOR EACH STATEMENT EXECUTE FUNCTION query_live_capture_users_statement('{id,user_id,client_id,scopes,expires_at,interval,approved,denied,consumed_at,created_at}', '{account,devices}', '{user}')");
        $this->addSql("CREATE TRIGGER query_capture_before BEFORE UPDATE OR DELETE ON oauth_refresh_tokens FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{id,chain_id,previous_refresh_token_id,access_token_id,revoked,expires_at,used_at,created_at}', '{account,devices,admin-security}', '{refresh-token,admin}')");
        $this->addSql("CREATE TRIGGER query_capture_insert AFTER INSERT ON oauth_refresh_tokens FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{id,chain_id,previous_refresh_token_id,access_token_id,revoked,expires_at,used_at,created_at}', '{account,devices,admin-security}', '{refresh-token,admin}')");
        $this->addSql("CREATE TRIGGER query_capture_before BEFORE UPDATE OR DELETE ON oauth_token_metadata FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{id,token_id,user_agent,device_operating_system,device_name,client_fingerprint,session_id,ip_address,ip_history,ip_change_count,country_code,city,created_at}', '{devices,admin-security}', '{token,admin}')");
        $this->addSql("CREATE TRIGGER query_capture_insert AFTER INSERT ON oauth_token_metadata FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{id,token_id,user_agent,device_operating_system,device_name,client_fingerprint,session_id,ip_address,ip_history,ip_change_count,country_code,city,created_at}', '{devices,admin-security}', '{token,admin}')");
        $this->addSql("CREATE TRIGGER query_capture_insert AFTER INSERT ON passkeys REFERENCING NEW TABLE AS query_new_rows FOR EACH STATEMENT EXECUTE FUNCTION query_live_capture_users_statement('{id,public_id,user_id,name,created_at}', '{account}', '{user}')");
        $this->addSql("CREATE TRIGGER query_capture_update AFTER UPDATE ON passkeys REFERENCING OLD TABLE AS query_old_rows NEW TABLE AS query_new_rows FOR EACH STATEMENT EXECUTE FUNCTION query_live_capture_users_statement('{id,public_id,user_id,name,created_at}', '{account}', '{user}')");
        $this->addSql("CREATE TRIGGER query_capture_delete AFTER DELETE ON passkeys REFERENCING OLD TABLE AS query_old_rows FOR EACH STATEMENT EXECUTE FUNCTION query_live_capture_users_statement('{id,public_id,user_id,name,created_at}', '{account}', '{user}')");
        $this->addSql("CREATE TRIGGER query_capture_insert AFTER INSERT ON player_preferences REFERENCING NEW TABLE AS query_new_rows FOR EACH STATEMENT EXECUTE FUNCTION query_live_capture_users_statement('{id,user_id,payload,version,created_at}', '{preferences}', '{user}')");
        $this->addSql("CREATE TRIGGER query_capture_update AFTER UPDATE ON player_preferences REFERENCING OLD TABLE AS query_old_rows NEW TABLE AS query_new_rows FOR EACH STATEMENT EXECUTE FUNCTION query_live_capture_users_statement('{id,user_id,payload,version,created_at}', '{preferences}', '{user}')");
        $this->addSql("CREATE TRIGGER query_capture_delete AFTER DELETE ON player_preferences REFERENCING OLD TABLE AS query_old_rows FOR EACH STATEMENT EXECUTE FUNCTION query_live_capture_users_statement('{id,user_id,payload,version,created_at}', '{preferences}', '{user}')");
        $this->addSql("CREATE TRIGGER query_capture_before BEFORE UPDATE OR DELETE ON playlist_collaborators FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{id,playlist_id,user_id,role,created_at}', '{playlists,account}', '{playlist,user}')");
        $this->addSql("CREATE TRIGGER query_capture_insert AFTER INSERT ON playlist_collaborators FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{id,playlist_id,user_id,role,created_at}', '{playlists,account}', '{playlist,user}')");
        $this->addSql("CREATE TRIGGER query_capture_before BEFORE UPDATE OR DELETE ON playlist_song FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{id,playlist_id,song_id,position,created_at}', '{playlists}', '{playlist}')");
        $this->addSql("CREATE TRIGGER query_capture_insert AFTER INSERT ON playlist_song FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{id,playlist_id,song_id,position,created_at}', '{playlists}', '{playlist}')");
        $this->addSql("CREATE TRIGGER query_capture_before BEFORE UPDATE OR DELETE ON playlist_statistics FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{id,playlist_id,views,plays,shares,favorites,created_at}', '{playlists}', '{playlist}')");
        $this->addSql("CREATE TRIGGER query_capture_insert AFTER INSERT ON playlist_statistics FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{id,playlist_id,views,plays,shares,favorites,created_at}', '{playlists}', '{playlist}')");
        $this->addSql("CREATE TRIGGER query_capture_before BEFORE UPDATE OR DELETE ON playlists FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{id,public_id,user_id,name,description,is_public,is_collaborative,is_smart,smart_rules,created_at}', '{playlists}', '{playlist-self}')");
        $this->addSql("CREATE TRIGGER query_capture_insert AFTER INSERT ON playlists FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{id,public_id,user_id,name,description,is_public,is_collaborative,is_smart,smart_rules,created_at}', '{playlists}', '{playlist-self}')");
        $this->addSql("CREATE TRIGGER query_capture_insert AFTER INSERT ON preference_history REFERENCING NEW TABLE AS query_new_rows FOR EACH STATEMENT EXECUTE FUNCTION query_live_capture_users_statement('{id,user_id,preference_type,version,payload,created_at}', '{preferences}', '{user}')");
        $this->addSql("CREATE TRIGGER query_capture_update AFTER UPDATE ON preference_history REFERENCING OLD TABLE AS query_old_rows NEW TABLE AS query_new_rows FOR EACH STATEMENT EXECUTE FUNCTION query_live_capture_users_statement('{id,user_id,preference_type,version,payload,created_at}', '{preferences}', '{user}')");
        $this->addSql("CREATE TRIGGER query_capture_delete AFTER DELETE ON preference_history REFERENCING OLD TABLE AS query_old_rows FOR EACH STATEMENT EXECUTE FUNCTION query_live_capture_users_statement('{id,user_id,preference_type,version,payload,created_at}', '{preferences}', '{user}')");
        $this->addSql("CREATE TRIGGER query_capture_insert AFTER INSERT ON push_subscriptions REFERENCING NEW TABLE AS query_new_rows FOR EACH STATEMENT EXECUTE FUNCTION query_live_capture_users_statement('{id,user_id,content_encoding,user_agent,created_at}', '{notifications}', '{user}')");
        $this->addSql("CREATE TRIGGER query_capture_update AFTER UPDATE ON push_subscriptions REFERENCING OLD TABLE AS query_old_rows NEW TABLE AS query_new_rows FOR EACH STATEMENT EXECUTE FUNCTION query_live_capture_users_statement('{id,user_id,content_encoding,user_agent,created_at}', '{notifications}', '{user}')");
        $this->addSql("CREATE TRIGGER query_capture_delete AFTER DELETE ON push_subscriptions REFERENCING OLD TABLE AS query_old_rows FOR EACH STATEMENT EXECUTE FUNCTION query_live_capture_users_statement('{id,user_id,content_encoding,user_agent,created_at}', '{notifications}', '{user}')");
        $this->addSql("CREATE TRIGGER query_capture_insert AFTER INSERT ON radio_sessions REFERENCING NEW TABLE AS query_new_rows FOR EACH STATEMENT EXECUTE FUNCTION query_live_capture_users_statement('{id,user_id,active_station_id,active_stream_url,state,created_at}', '{radio}', '{user}')");
        $this->addSql("CREATE TRIGGER query_capture_update AFTER UPDATE ON radio_sessions REFERENCING OLD TABLE AS query_old_rows NEW TABLE AS query_new_rows FOR EACH STATEMENT EXECUTE FUNCTION query_live_capture_users_statement('{id,user_id,active_station_id,active_stream_url,state,created_at}', '{radio}', '{user}')");
        $this->addSql("CREATE TRIGGER query_capture_delete AFTER DELETE ON radio_sessions REFERENCING OLD TABLE AS query_old_rows FOR EACH STATEMENT EXECUTE FUNCTION query_live_capture_users_statement('{id,user_id,active_station_id,active_stream_url,state,created_at}', '{radio}', '{user}')");
        $this->addSql("CREATE TRIGGER query_capture_before BEFORE UPDATE OR DELETE ON radio_sources FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{id,public_id,name,type,sync_url,sync_schedule,is_active,created_at}', '{radio}', '{public,admin}')");
        $this->addSql("CREATE TRIGGER query_capture_insert AFTER INSERT ON radio_sources FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{id,public_id,name,type,sync_url,sync_schedule,is_active,created_at}', '{radio}', '{public,admin}')");
        $this->addSql("CREATE TRIGGER query_capture_before BEFORE UPDATE OR DELETE ON radio_stations FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{id,public_id,source_id,external_id,name,country,language,genres,tags,streams,logo,website,created_at}', '{radio}', '{public,admin}')");
        $this->addSql("CREATE TRIGGER query_capture_insert AFTER INSERT ON radio_stations FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{id,public_id,source_id,external_id,name,country,language,genres,tags,streams,logo,website,created_at}', '{radio}', '{public,admin}')");
        $this->addSql("CREATE TRIGGER query_capture_before BEFORE UPDATE OR DELETE ON recommendation_jobs FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{id,public_id,is_full,user_id,status,total_songs,completed_songs,current_strategy,strategy_counts,fail_reason,created_at,started_at,completed_at,metadata,original_job_id}', '{recommendations,admin-metadata}', '{recommendation,admin}')");
        $this->addSql("CREATE TRIGGER query_capture_insert AFTER INSERT ON recommendation_jobs FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{id,public_id,is_full,user_id,status,total_songs,completed_songs,current_strategy,strategy_counts,fail_reason,created_at,started_at,completed_at,metadata,original_job_id}', '{recommendations,admin-metadata}', '{recommendation,admin}')");
        $this->addSql("CREATE TRIGGER query_capture_before BEFORE UPDATE OR DELETE ON recommendations FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{id,public_id,user_id,name,source_type,source_id,target_type,target_id,score,position,created_at}', '{recommendations,admin-metadata}', '{recommendation,admin}')");
        $this->addSql("CREATE TRIGGER query_capture_insert AFTER INSERT ON recommendations FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{id,public_id,user_id,name,source_type,source_id,target_type,target_id,score,position,created_at}', '{recommendations,admin-metadata}', '{recommendation,admin}')");
        $this->addSql("CREATE TRIGGER query_capture_before BEFORE UPDATE OR DELETE ON scheduled_jobs FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{id,public_id,revision,evaluated_through,recovery_after,name,expression,job_type,command,status,description,parameters,created_at,last_run_at,next_run_at,last_result,run_count,last_failure_at,last_error}', '{admin-jobs}', '{admin}')");
        $this->addSql("CREATE TRIGGER query_capture_insert AFTER INSERT ON scheduled_jobs FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{id,public_id,revision,evaluated_through,recovery_after,name,expression,job_type,command,status,description,parameters,created_at,last_run_at,next_run_at,last_result,run_count,last_failure_at,last_error}', '{admin-jobs}', '{admin}')");
        $this->addSql("CREATE TRIGGER query_capture_before BEFORE UPDATE OR DELETE ON scheduler_occurrence_executions FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{occurrence_id,job_id,attempt_id,deployment_namespace,deployment_boot_id,deployment_epoch,started_at,returned_at}', '{admin-jobs}', '{admin}')");
        $this->addSql("CREATE TRIGGER query_capture_insert AFTER INSERT ON scheduler_occurrence_executions FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{occurrence_id,job_id,attempt_id,deployment_namespace,deployment_boot_id,deployment_epoch,started_at,returned_at}', '{admin-jobs}', '{admin}')");
        $this->addSql("CREATE TRIGGER query_capture_before BEFORE UPDATE OR DELETE ON scheduler_occurrences FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{id,job_id,scheduled_for,origin,job_type,command,dispatch_after,dispatch_token,dispatched_at,created_at,public_id,job_public_id}', '{admin-jobs}', '{admin}')");
        $this->addSql("CREATE TRIGGER query_capture_insert AFTER INSERT ON scheduler_occurrences FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{id,job_id,scheduled_for,origin,job_type,command,dispatch_after,dispatch_token,dispatched_at,created_at,public_id,job_public_id}', '{admin-jobs}', '{admin}')");
        $this->addSql("CREATE TRIGGER query_capture_before BEFORE UPDATE OR DELETE ON songs FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{id,public_id,album_id,title,path,size,mime_type,length,lyrics,track,disc,year,comment,hash,bitrate,sample_rate,channels,codec,explicit,energy,danceability,valence,acousticness,instrumentalness,liveness,spechiness,loudness,mbid,discogs_id,spotify_id,locked_fields,created_at}', '{catalog,libraries,activity,playlists,recommendations,admin-metadata}', '{album}')");
        $this->addSql("CREATE TRIGGER query_capture_insert AFTER INSERT ON songs FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{id,public_id,album_id,title,path,size,mime_type,length,lyrics,track,disc,year,comment,hash,bitrate,sample_rate,channels,codec,explicit,energy,danceability,valence,acousticness,instrumentalness,liveness,spechiness,loudness,mbid,discogs_id,spotify_id,locked_fields,created_at}', '{catalog,libraries,activity,playlists,recommendations,admin-metadata}', '{album}')");
        $this->addSql("CREATE TRIGGER query_capture_insert AFTER INSERT ON starred_stations REFERENCING NEW TABLE AS query_new_rows FOR EACH STATEMENT EXECUTE FUNCTION query_live_capture_users_statement('{id,user_id,station_id,starred_at}', '{radio}', '{user}')");
        $this->addSql("CREATE TRIGGER query_capture_update AFTER UPDATE ON starred_stations REFERENCING OLD TABLE AS query_old_rows NEW TABLE AS query_new_rows FOR EACH STATEMENT EXECUTE FUNCTION query_live_capture_users_statement('{id,user_id,station_id,starred_at}', '{radio}', '{user}')");
        $this->addSql("CREATE TRIGGER query_capture_delete AFTER DELETE ON starred_stations REFERENCING OLD TABLE AS query_old_rows FOR EACH STATEMENT EXECUTE FUNCTION query_live_capture_users_statement('{id,user_id,station_id,starred_at}', '{radio}', '{user}')");
        $this->addSql("CREATE TRIGGER query_capture_before BEFORE UPDATE OR DELETE ON system_settings FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{key,value}', '{admin-settings,admin-metadata,recommendations}', '{admin}')");
        $this->addSql("CREATE TRIGGER query_capture_insert AFTER INSERT ON system_settings FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{key,value}', '{admin-settings,admin-metadata,recommendations}', '{admin}')");
        $this->addSql("CREATE TRIGGER query_capture_insert AFTER INSERT ON third_party_credentials REFERENCING NEW TABLE AS query_new_rows FOR EACH STATEMENT EXECUTE FUNCTION query_live_capture_users_statement('{id,public_id,user_id,provider,expires_at,meta,created_at}', '{account}', '{user}')");
        $this->addSql("CREATE TRIGGER query_capture_update AFTER UPDATE ON third_party_credentials REFERENCING OLD TABLE AS query_old_rows NEW TABLE AS query_new_rows FOR EACH STATEMENT EXECUTE FUNCTION query_live_capture_users_statement('{id,public_id,user_id,provider,expires_at,meta,created_at}', '{account}', '{user}')");
        $this->addSql("CREATE TRIGGER query_capture_delete AFTER DELETE ON third_party_credentials REFERENCING OLD TABLE AS query_old_rows FOR EACH STATEMENT EXECUTE FUNCTION query_live_capture_users_statement('{id,public_id,user_id,provider,expires_at,meta,created_at}', '{account}', '{user}')");
        $this->addSql("CREATE TRIGGER query_capture_before BEFORE UPDATE OR DELETE ON transcode_jobs FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{id,public_id,video_id,owner_user_id,quality_tier_name,status,reference_count,total_segments,completed_segments,output_directory,init_segment_path,segment_map,probe_data,video_codec,audio_codec,video_bitrate,audio_bitrate,width,height,framerate,fail_reason,measured_loudness,audio_track_languages,audio_segment_map,created_at}', '{admin-media}', '{transcode-owner,admin}')");
        $this->addSql("CREATE TRIGGER query_capture_insert AFTER INSERT ON transcode_jobs FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{id,public_id,video_id,owner_user_id,quality_tier_name,status,reference_count,total_segments,completed_segments,output_directory,init_segment_path,segment_map,probe_data,video_codec,audio_codec,video_bitrate,audio_bitrate,width,height,framerate,fail_reason,measured_loudness,audio_track_languages,audio_segment_map,created_at}', '{admin-media}', '{transcode-owner,admin}')");
        $this->addSql("CREATE TRIGGER query_capture_before BEFORE UPDATE OR DELETE ON transcode_runtime_state FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{job_id,session_id,request_id,desired,desired_revision,observed_revision,generation,outcome,output_directory,error,admission_deadline}', '{admin-media}', '{admin}')");
        $this->addSql("CREATE TRIGGER query_capture_insert AFTER INSERT ON transcode_runtime_state FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{job_id,session_id,request_id,desired,desired_revision,observed_revision,generation,outcome,output_directory,error,admission_deadline}', '{admin-media}', '{admin}')");
        $this->addSql("CREATE TRIGGER query_capture_before BEFORE UPDATE OR DELETE ON transcode_sessions FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{id,public_id,user_id,video_id,state,priority,audio_profile,current_segment_index,wall_clock_offset,metrics,created_at,job_id}', '{admin-media,sessions}', '{user,admin}')");
        $this->addSql("CREATE TRIGGER query_capture_insert AFTER INSERT ON transcode_sessions FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{id,public_id,user_id,video_id,state,priority,audio_profile,current_segment_index,wall_clock_offset,metrics,created_at,job_id}', '{admin-media,sessions}', '{user,admin}')");
        $this->addSql("CREATE TRIGGER query_capture_insert AFTER INSERT ON user_accent_colors REFERENCING NEW TABLE AS query_new_rows FOR EACH STATEMENT EXECUTE FUNCTION query_live_capture_users_statement('{id,user_id,accent_color,created_at}', '{preferences}', '{user}')");
        $this->addSql("CREATE TRIGGER query_capture_update AFTER UPDATE ON user_accent_colors REFERENCING OLD TABLE AS query_old_rows NEW TABLE AS query_new_rows FOR EACH STATEMENT EXECUTE FUNCTION query_live_capture_users_statement('{id,user_id,accent_color,created_at}', '{preferences}', '{user}')");
        $this->addSql("CREATE TRIGGER query_capture_delete AFTER DELETE ON user_accent_colors REFERENCING OLD TABLE AS query_old_rows FOR EACH STATEMENT EXECUTE FUNCTION query_live_capture_users_statement('{id,user_id,accent_color,created_at}', '{preferences}', '{user}')");
        $this->addSql("CREATE TRIGGER query_capture_insert AFTER INSERT ON user_favorites REFERENCING NEW TABLE AS query_new_rows FOR EACH STATEMENT EXECUTE FUNCTION query_live_capture_users_statement('{id,public_id,user_id,entity_type,entity_public_id,created_at}', '{activity,catalog}', '{user}')");
        $this->addSql("CREATE TRIGGER query_capture_update AFTER UPDATE ON user_favorites REFERENCING OLD TABLE AS query_old_rows NEW TABLE AS query_new_rows FOR EACH STATEMENT EXECUTE FUNCTION query_live_capture_users_statement('{id,public_id,user_id,entity_type,entity_public_id,created_at}', '{activity,catalog}', '{user}')");
        $this->addSql("CREATE TRIGGER query_capture_delete AFTER DELETE ON user_favorites REFERENCING OLD TABLE AS query_old_rows FOR EACH STATEMENT EXECUTE FUNCTION query_live_capture_users_statement('{id,public_id,user_id,entity_type,entity_public_id,created_at}', '{activity,catalog}', '{user}')");
        $this->addSql("CREATE TRIGGER query_capture_before BEFORE UPDATE OR DELETE ON user_library_access FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{user_id,library_id,granted_at}', '{account,libraries,catalog,activity,playlists,recommendations,admin-users}', '{user,library,admin}')");
        $this->addSql("CREATE TRIGGER query_capture_insert AFTER INSERT ON user_library_access FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{user_id,library_id,granted_at}', '{account,libraries,catalog,activity,playlists,recommendations,admin-users}', '{user,library,admin}')");
        $this->addSql("CREATE TRIGGER query_capture_insert AFTER INSERT ON user_settings REFERENCING NEW TABLE AS query_new_rows FOR EACH STATEMENT EXECUTE FUNCTION query_live_capture_users_statement('{user_id,key,value}', '{preferences}', '{user}')");
        $this->addSql("CREATE TRIGGER query_capture_update AFTER UPDATE ON user_settings REFERENCING OLD TABLE AS query_old_rows NEW TABLE AS query_new_rows FOR EACH STATEMENT EXECUTE FUNCTION query_live_capture_users_statement('{user_id,key,value}', '{preferences}', '{user}')");
        $this->addSql("CREATE TRIGGER query_capture_delete AFTER DELETE ON user_settings REFERENCING OLD TABLE AS query_old_rows FOR EACH STATEMENT EXECUTE FUNCTION query_live_capture_users_statement('{user_id,key,value}', '{preferences}', '{user}')");
        $this->addSql("CREATE TRIGGER query_capture_insert AFTER INSERT ON user_sidebar_configs REFERENCING NEW TABLE AS query_new_rows FOR EACH STATEMENT EXECUTE FUNCTION query_live_capture_users_statement('{id,user_id,media_type,items,created_at}', '{preferences}', '{user}')");
        $this->addSql("CREATE TRIGGER query_capture_update AFTER UPDATE ON user_sidebar_configs REFERENCING OLD TABLE AS query_old_rows NEW TABLE AS query_new_rows FOR EACH STATEMENT EXECUTE FUNCTION query_live_capture_users_statement('{id,user_id,media_type,items,created_at}', '{preferences}', '{user}')");
        $this->addSql("CREATE TRIGGER query_capture_delete AFTER DELETE ON user_sidebar_configs REFERENCING OLD TABLE AS query_old_rows FOR EACH STATEMENT EXECUTE FUNCTION query_live_capture_users_statement('{id,user_id,media_type,items,created_at}', '{preferences}', '{user}')");
        $this->addSql("CREATE TRIGGER query_capture_insert AFTER INSERT ON user_theme_moods REFERENCING NEW TABLE AS query_new_rows FOR EACH STATEMENT EXECUTE FUNCTION query_live_capture_users_statement('{id,user_id,theme_mood,created_at}', '{preferences}', '{user}')");
        $this->addSql("CREATE TRIGGER query_capture_update AFTER UPDATE ON user_theme_moods REFERENCING OLD TABLE AS query_old_rows NEW TABLE AS query_new_rows FOR EACH STATEMENT EXECUTE FUNCTION query_live_capture_users_statement('{id,user_id,theme_mood,created_at}', '{preferences}', '{user}')");
        $this->addSql("CREATE TRIGGER query_capture_delete AFTER DELETE ON user_theme_moods REFERENCING OLD TABLE AS query_old_rows FOR EACH STATEMENT EXECUTE FUNCTION query_live_capture_users_statement('{id,user_id,theme_mood,created_at}', '{preferences}', '{user}')");
        $this->addSql("CREATE TRIGGER query_capture_before BEFORE UPDATE OR DELETE ON users FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{id,public_id,name,email,email_verified_at,created_at,roles,disabled}', '{account,admin-users,activity}', '{self,admin}')");
        $this->addSql("CREATE TRIGGER query_capture_insert AFTER INSERT ON users FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{id,public_id,name,email,email_verified_at,created_at,roles,disabled}', '{account,admin-users,activity}', '{self,admin}')");
        $this->addSql("CREATE TRIGGER query_capture_before BEFORE UPDATE OR DELETE ON videos FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{id,public_id,path,hash,duration,height,width,video_bitrate,framerate,probe,created_at}', '{catalog,libraries,activity,recommendations,admin-media,admin-metadata}', '{video}')");
        $this->addSql("CREATE TRIGGER query_capture_insert AFTER INSERT ON videos FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{id,public_id,path,hash,duration,height,width,video_bitrate,framerate,probe,created_at}', '{catalog,libraries,activity,recommendations,admin-media,admin-metadata}', '{video}')");
        $this->addSql("CREATE TRIGGER query_capture_before BEFORE UPDATE OR DELETE ON webhooks FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{id,public_id,url,category_filter,created_at}', '{admin-settings}', '{admin}')");
        $this->addSql("CREATE TRIGGER query_capture_insert AFTER INSERT ON webhooks FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{id,public_id,url,category_filter,created_at}', '{admin-settings}', '{admin}')");
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Captured changes and revision tombstones cannot be reconstructed.');
    }
}
