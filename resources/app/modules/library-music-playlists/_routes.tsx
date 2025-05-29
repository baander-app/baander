import { Route, Routes } from 'react-router-dom';
import { PlayList } from '@/modules/library-music-playlists/playlist/playlist.tsx';


export const LibraryMusicPlaylistsRoutes = () => {
  return (
    <Routes>
      <Route path="/:playlistId" element={<PlayList />} />
    </Routes>
  )
}

export interface MusicPlaylistParams {
  playlistId: string;
}