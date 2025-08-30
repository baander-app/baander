import { Navigate, Route, Routes } from 'react-router-dom';
import { NotFound } from '@/modules/error/routes/not-found.tsx';


export const ErrorRoutes = () => {
  return (
    <Routes>
      <Route path="/not-found" element={<NotFound />} />
      <Route path="*" element={<Navigate to="/" />} />
    </Routes>
  )
}
