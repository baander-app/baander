import { SettingsPageLayout } from '@/features/feature-user-settings/layouts/settings-page-layout.tsx';
import { Equalizer } from '@/features/feature-equalizer/equalizer.tsx';
import { useBlockBodyScroll } from '@/hooks/use-block-body-scroll.ts';

export function EqualizerSettings() {
  useBlockBodyScroll();

  return (
    <SettingsPageLayout title="Equalizer">
      <Equalizer />
    </SettingsPageLayout>
  )
}