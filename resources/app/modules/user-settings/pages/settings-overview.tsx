import { SettingsPageLayout } from '@/modules/user-settings/layouts/settings-page-layout.tsx';
import { Box, Button } from '@radix-ui/themes';
import { DevPanel } from '@/modules/user-settings/features/dev-panel.tsx';
import { useAppDispatch, useAppSelector } from '@/store/hooks.ts';
import { setTheme } from '@/store/users/ui-slice.ts';
import { useAuthStore } from '@/modules/auth/store';

export function SettingsOverview() {
  const { theme } = useAppSelector(state => state.ui);
  const dispatch = useAppDispatch();
  const { logout } = useAuthStore();

  const toggleTheme = () => {
    dispatch(setTheme(theme === 'light' ? 'dark' : 'light'));
  }

  const logoutUser = () => {
    logout();
  }

  return (
    <SettingsPageLayout title="Settings">
      <a href="#" onClick={() => logoutUser()}>
        <span>Logout</span>
      </a>

      <Box>
        <Button onClick={() => toggleTheme()}>Toggle Theme</Button>
      </Box>

      <Box mt="2">
        <DevPanel/>
      </Box>

    </SettingsPageLayout>
  );
}
