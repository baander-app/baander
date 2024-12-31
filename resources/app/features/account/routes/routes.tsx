import { Route, Routes } from 'react-router-dom';
import { TwoFactor } from '@/features/account/routes/two-factor.tsx';

export const AccountRoutes = () => {
  return (
    <Routes>
      <Route path="/two-factor" element={<TwoFactor />} />
    </Routes>
  )
}