import { ReactNode } from 'react';
import styles from './root-layout.module.scss';
import { AppShell, Burger, Group, Text } from '@mantine/core';
import { NavLink } from '@/components/nav-link.tsx';
import { useDisclosure } from '@mantine/hooks';
import { UserMenu } from '@/layouts/root-layout/user-menu.tsx';
import { LibraryNav } from '@/layouts/root-layout/library-nav/library-nav.tsx';
import { lazyImport } from '@/utils/lazy-import.ts';

const { BaanderLogo } = lazyImport(() => import('@/components/branding/baander-logo/baander-logo.tsx'), 'BaanderLogo');
const { InlinePlayer } = lazyImport(() => import('@/features/library-music-player/inline-player/inline-player.tsx'), 'InlinePlayer');

export function RootLayout(props: { children?: ReactNode }) {
  const [opened, { toggle }] = useDisclosure();

  return (
    <AppShell
      layout="alt"
      footer={{ height: 85 }}
      navbar={{ width: 200, breakpoint: 'sm', collapsed: { mobile: !opened } }}
      padding="sm"
    >
      <AppShell.Navbar>
        <Group>
          <Burger opened={opened} onClick={toggle} hiddenFrom="sm" size="sm"/>

          <Group h="100%" px="md">
            <Burger opened={opened} onClick={toggle} hiddenFrom="sm" size="sm"/>
            <BaanderLogo/>
            <Text>{import.meta.env.VITE_APP_NAME}</Text>
          </Group>
        </Group>

        <div className={styles.navbarMain}>
          <LibraryNav/>
        </div>

        <NavLink to="/dashboard" label="Dashboard"/>


        <UserMenu/>
      </AppShell.Navbar>

      <AppShell.Main className={styles.main}>
        {props.children}
      </AppShell.Main>

      <AppShell.Footer>
        <InlinePlayer/>
      </AppShell.Footer>
    </AppShell>
  );
}
