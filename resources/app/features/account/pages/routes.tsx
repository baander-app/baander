import { Route, Routes } from 'react-router-dom';
import { TwoFactor } from '@/features/account/pages/two-factor.tsx';
import { Sessions } from '@/features/account/pages/sessions.tsx';

export const AccountRoutes = () => {
  return (
    <Routes>
      <Route path="/two-factor" element={<TwoFactor />} />
      <Route path="/sessions" element={<Sessions />} />
    </Routes>
  )
}