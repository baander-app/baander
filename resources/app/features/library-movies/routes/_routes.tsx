import { Route, Routes } from 'react-router-dom';
import { Overview } from '@/features/library-movies/routes/overview.tsx';


export const LibraryMoviesRoutes = () => {
  return (
    <Routes>
      <Route path="/" element={<Overview/>}/>
    </Routes>
  );
};