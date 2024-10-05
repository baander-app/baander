import { SettingsPageLayout } from '@/features/feature-user-settings/layouts/settings-page-layout.tsx';
import { Equalizer } from '@/features/feature-equalizer/equalizer.tsx';

export function EqualizerSettings() {
  return (
    <SettingsPageLayout title="Equalizer">
      <Equalizer />
    </SettingsPageLayout>
  )
}