import { Navigate, Route, Routes } from 'react-router-dom';

import { CreateAccount } from '@/modules/auth/routes/create-account.tsx';
import { ForgotPassword } from '@/modules/auth/routes/forgot-password.tsx';

export const AuthRoutes = () => {
  return (
    <Routes>
      <Route path="/create-account" element={<CreateAccount />} />
      <Route path="/forgot-password" element={<ForgotPassword />} />
      <Route path="*" element={<Navigate to="/create-account" />} />
    </Routes>
  )
}
