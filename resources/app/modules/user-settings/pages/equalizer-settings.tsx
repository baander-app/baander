import { SettingsPageLayout } from '@/modules/user-settings/layouts/settings-page-layout.tsx';
import { Equalizer } from '@/modules/equalizer/equalizer.tsx';
import { useBlockBodyScroll } from '@/hooks/use-block-body-scroll.ts';

export function EqualizerSettings() {
  useBlockBodyScroll();

  return (
    <SettingsPageLayout title="Equalizer">
      <Equalizer />
    </SettingsPageLayout>
  )
}