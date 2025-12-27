import { Route, Routes } from 'react-router-dom';
import { PlayList } from '@/app/modules/library-music-playlists/playlist/playlist.tsx';
import Playlists from '@/app/modules/library-music-playlists/routes/playlists';


export const LibraryMusicPlaylistsRoutes = () => {
  return (
    <Routes>
      <Route index element={<Playlists />} />
      <Route path="/:playlistId" element={<PlayList />} />
    </Routes>
  )
}

export interface MusicPlaylistParams {
  playlistId: string;
}