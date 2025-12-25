import { Navigate, Route, Routes } from 'react-router-dom';

import Artists from '@/app/modules/library-music/routes/artists.tsx';
import Albums  from '@/app/modules/library-music/routes/albums.tsx';
import Songs   from '@/app/modules/library-music/routes/songs.tsx';

export const LibraryMusicRoutes = () => {
  return (
    <Routes>
      <Route path="/artists" element={<Artists/>}></Route>
      <Route path="/albums" element={<Albums/>}></Route>
      <Route path="/songs" element={<Songs/>}></Route>
      <Route path="/*" element={<Navigate to="/" />} />
    </Routes>
  );
};

export interface LibraryParams {
  library: string;
}
