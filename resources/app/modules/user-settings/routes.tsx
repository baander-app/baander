import { Route, Routes } from 'react-router-dom';
import { EqualizerSettings } from '@/modules/user-settings/pages/equalizer-settings.tsx';
import { SettingsOverview } from '@/modules/user-settings/pages/settings-overview.tsx';
import { Sessions } from '@/modules/user-settings/pages/sessions.tsx';
import { Passkeys } from '@/modules/user-settings/pages/passkeys.tsx';

export const UserSettingsRoutes = () => {
  return (
    <Routes>
      <Route path="/" element={<SettingsOverview />} />
      <Route path="/equalizer" element={<EqualizerSettings/>}/>
      <Route path="/sessions" element={<Sessions />} />
      <Route path="/passkeys" element={<Passkeys />} />
    </Routes>
  );
};