import { Route, Routes } from 'react-router-dom';
import { EqualizerSettings } from '@/modules/user-settings/routes/equalizer-settings.tsx';

export const UserSettingsRoutes = () => {
  return (
    <Routes>
      <Route path="/equalizer" element={<EqualizerSettings/>}/>
    </Routes>
  );
};