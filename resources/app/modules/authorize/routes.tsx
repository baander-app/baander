import { Route, Routes } from 'react-router-dom';
import { ConfirmPassword } from '@/app/modules/authorize/confirm-password.tsx';

export const AuthorizeRoutes = () => {
  return (
    <Routes>
      <Route path="confirm-password" element={<ConfirmPassword />} />
    </Routes>
  )
}