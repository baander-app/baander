import { ReactNode, useMemo } from 'react';
import styles from './root-layout.module.scss';
import { AppShell, Burger, Group, Text } from '@mantine/core';
import { NavLink } from '@/ui/nav-link.tsx';
import { useDisclosure } from '@mantine/hooks';
import { UserMenu } from '@/layouts/root-layout/components/user-menu.tsx';
import { MusicLibraryNav } from '@/layouts/root-layout/components/music-library-nav.tsx';
import { lazyImport } from '@/utils/lazy-import.ts';
import { LibraryNav } from '@/layouts/root-layout/components/library-nav.tsx';
import { useLibraryServiceLibrariesIndex } from '@/api-client/queries';

const { BaanderLogo } = lazyImport(() => import('@/ui/branding/baander-logo/baander-logo.tsx'), 'BaanderLogo');
const { InlinePlayer } = lazyImport(() => import('@/modules/library-music-player/inline-player/inline-player.tsx'), 'InlinePlayer');

export function RootLayout(props: { children?: ReactNode }) {
  const [opened, { toggle }] = useDisclosure();
  const { data } = useLibraryServiceLibrariesIndex();

  const movieLibrary = useMemo(() => data?.data.find(library => library.type === 'movie'), [data]);
  const musicLibraries = useMemo(() => data?.data.filter(library => library.type === 'music') ?? [], [data]);

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
          <MusicLibraryNav libraries={musicLibraries}/>

          {movieLibrary && (
            <LibraryNav library={movieLibrary}/>
          )}
        </div>

        <div>

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
