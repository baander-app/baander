import { SettingsPageLayout } from '@/modules/user-settings/layouts/settings-page-layout.tsx';
import { Box } from '@radix-ui/themes';
import { DevPanel } from '@/modules/user-settings/features/dev-panel.tsx';
import { useAppDispatch } from '@/store/hooks.ts';
import { logoutUser } from '@/store/users/auth-slice.ts';

export function SettingsOverview() {
  const dispatch = useAppDispatch();

  return (
    <SettingsPageLayout title="Settings">
      <a href="#" onClick={() => dispatch(logoutUser())}>
        <span>Logout</span>
      </a>

      <Box mt="2">
        <DevPanel/>
      </Box>

    </SettingsPageLayout>
  );
}