import { Route, Routes } from 'react-router-dom';
import { SettingsOverview } from '@/app/modules/user-settings/pages/settings-overview.tsx';
import { Sessions } from '@/app/modules/user-settings/pages/sessions.tsx';
import { Passkeys } from '@/app/modules/user-settings/pages/passkeys.tsx';

export const UserSettingsRoutes = () => {
  return (
    <Routes>
      <Route path="/" element={<SettingsOverview />} />
      <Route path="/sessions" element={<Sessions />} />
      <Route path="/passkeys" element={<Passkeys />} />
    </Routes>
  );
};