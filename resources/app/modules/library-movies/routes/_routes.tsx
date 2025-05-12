import { Route, Routes } from 'react-router-dom';
import { Overview } from '@/modules/library-movies/routes/overview.tsx';


export const LibraryMoviesRoutes = () => {
  return (
    <Routes>
      <Route path="/overview" element={<Overview/>}/>
    </Routes>
  );
};