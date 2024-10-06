import { Route, Routes } from 'react-router-dom';
import { Sessions } from '@/features/account/routes/sessions.tsx';
import { DevPanel } from '@/features/account/dev-panel.tsx';
import { Passkeys } from '@/features/account/routes/passkeys.tsx';

export const AccountRoutes = () => {
  return (
    <Routes>
      <Route path="/passkeys" element={<Passkeys />} />
      <Route path="/sessions" element={<Sessions />} />
      <Route path="/dev-panel" element={<DevPanel />} />
    </Routes>
  )
}