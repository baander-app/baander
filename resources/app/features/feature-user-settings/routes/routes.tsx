import { Route, Routes } from 'react-router-dom';
import { EqualizerSettings } from '@/features/feature-user-settings/routes/equalizer-settings.tsx';

export const UserSettingsRoutes = () => {
  return (
    <Routes>
      <Route path="/equalizer" element={<EqualizerSettings/>}/>
    </Routes>
  );
};