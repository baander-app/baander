import { Route, Routes } from 'react-router-dom';
import { Sessions } from '@/modules/account/routes/sessions.tsx';
import { DevPanel } from '@/modules/account/dev-panel.tsx';
import { Passkeys } from '@/modules/account/routes/passkeys.tsx';

export const AccountRoutes = () => {
  return (
    <Routes>
      <Route path="/passkeys" element={<Passkeys />} />
      <Route path="/sessions" element={<Sessions />} />
      <Route path="/dev-panel" element={<DevPanel />} />
    </Routes>
  )
}