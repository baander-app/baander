import { SettingsPageLayout } from '@/modules/user-settings/layouts/settings-page-layout.tsx';
import { LinkButton } from '@/ui/link-button.tsx';
import { Box, Container } from '@mantine/core';
import { DevPanel } from '@/modules/user-settings/features/dev-panel.tsx';
import { useAppDispatch } from '@/store/hooks.ts';
import styles from '@/layouts/root-layout/components/user-menu.module.scss';
import { logoutUser } from '@/store/users/auth-slice.ts';

export function SettingsOverview() {
  const dispatch = useAppDispatch();

  return (
    <SettingsPageLayout title="Settings">

      <Container>
        <Box mt="xl">
          <LinkButton to="passkeys">
            Passkeys
          </LinkButton>
        </Box>

        <Box mt="xl">
          <LinkButton to="sessions">
            Sessions
          </LinkButton>
        </Box>

        <Box mt="xl">
          <a className={styles.link} href="#" onClick={() => dispatch(logoutUser())}>
            <span>Logout</span>
          </a>
        </Box>

        <Box mt="xl">
          <DevPanel/>
        </Box>
      </Container>

    </SettingsPageLayout>
  )
}