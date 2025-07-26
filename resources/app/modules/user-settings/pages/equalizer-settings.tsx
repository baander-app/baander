import { SettingsPageLayout } from '@/modules/user-settings/layouts/settings-page-layout.tsx';
import { useBlockBodyScroll } from '@/hooks/use-block-body-scroll.ts';

export function EqualizerSettings() {
  useBlockBodyScroll();

  return (
    <SettingsPageLayout title="Equalizer">
    </SettingsPageLayout>
  )
}