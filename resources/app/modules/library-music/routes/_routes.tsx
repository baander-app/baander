import { Route, Routes } from 'react-router-dom';

import Artists from '@/modules/library-music/routes/artists.tsx';
import Albums from '@/modules/library-music/routes/albums.tsx';
import Songs from '@/modules/library-music/routes/songs.tsx';

export const LibraryMusicRoutes = () => {
  return (
    <Routes>
      <Route path="/artists" element={<Artists />}></Route>
      <Route path="/albums" element={<Albums />}></Route>
      <Route path="/songs" element={<Songs />}></Route>
    </Routes>
  )
}

export interface LibraryParams {
  library: string;
}