import { SettingsPageLayout } from '@/app/modules/user-settings/layouts/settings-page-layout.tsx';
import { Box, Button, Separator } from '@radix-ui/themes';
import { DevPanel } from '@/app/modules/user-settings/features/dev-panel.tsx';
import { useAuthStore } from '@/app/modules/auth/store';
import { QueueSettingsSection } from '@/app/modules/user-settings/components/queue-settings-section/queue-settings-section';

export function SettingsOverview() {
  // const { theme } = useAppSelector(state => state.ui);
  // const dispatch = useAppDispatch();
  const { logout } = useAuthStore();

  const toggleTheme = () => {
    // dispatch(setTheme(theme === 'light' ? 'dark' : 'light'));
  }

  const logoutUser = () => {
    logout();
  }

  return (
    <SettingsPageLayout title="Settings">
      {/* Queue Settings */}
      <Box mb="6">
        <QueueSettingsSection />
      </Box>

      <Separator size="1" my="6" style={{ backgroundColor: 'var(--gray-6)' }} />

      {/* Other Settings */}
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
