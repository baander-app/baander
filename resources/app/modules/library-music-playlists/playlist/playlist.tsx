import { usePathParam } from '@/app/hooks/use-path-param';
import { MusicPlaylistParams } from '@/app/modules/library-music-playlists/_routes.tsx';
import { PlaylistDetail } from '@/app/modules/library-music-playlists/components/playlist-detail/playlist-detail';

export function PlayList() {
  const { playlistId } = usePathParam<MusicPlaylistParams>();

  if (!playlistId) {
    return null;
  }

  return (
    <div style={{ width: '100%', height: '100%' }}>
      <PlaylistDetail playlistId={playlistId} librarySlug="music" />
    </div>
  );
}
