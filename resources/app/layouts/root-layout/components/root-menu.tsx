import { Box, Flex, ScrollArea, Text }      from '@radix-ui/themes';
import { NavLink }                          from '@/ui/nav-link';
import { lazyImport }                       from '@/utils/lazy-import';
import { Iconify }                          from '@/ui/icons/iconify';
import styles                               from './root-menu.module.scss';
import { useMemo }                          from 'react';
import { useLibraryServiceGetApiLibraries } from '@/api-client/queries';

const { Brand } = lazyImport(() => import('@/ui/brand/Brand'), 'Brand');

interface MenuLink {
  label: string;
  to?: string;
  href?: string;
}

interface MenuSection {
  label: string;
  iconName: string;
  links: MenuLink[];
}

export function RootMenu() {
  const { data } = useLibraryServiceGetApiLibraries();

  const musicLibraries = useMemo(() => data?.data.filter(library => library.type === 'music') ?? [], [data]);
  const movieLibraries = useMemo(() => data?.data.filter(library => library.type === 'movie') ?? [], [data]);

  const librariesMenu: MenuSection[] = useMemo(() => {
    const sections: MenuSection[] = [];

    if (musicLibraries.length > 0) {
      for (const library of musicLibraries) {
        sections.push({
          label: library.name,
          iconName: 'ion:musical-notes',
          links: [
            {
              label: 'Artists',
              to: `/library/${library.slug}/artists`,
            },
            {
              label: 'Albums',
              to: `/library/${library.slug}/albums`,
            },
            {
              label: 'Songs',
              to: `/library/${library.slug}/songs`,
            },
            {
              label: 'Genres',
              to: `/library/${library.slug}/genres`,
            },
          ],
        });
      }
    }

    if (movieLibraries.length > 0) {
      for (const library of movieLibraries) {
        sections.push({
          label: library.name,
          iconName: 'ion-film-outline',
          links: [
            {
              label: 'Overview',
              to: `/library/${library.slug}/overview`,
            },
          ],
        });
      }
    }

    return sections;
  }, [musicLibraries]);

  const staticMenu: MenuSection[] = [
    {
      label: 'Navigation',
      iconName: 'heroicons:home',
      links: [
        { label: 'Dashboard', to: '/dashboard' },
      ],
    },
    {
      label: 'User',
      iconName: 'heroicons:user-circle-solid',
      links: [
        { label: 'Settings', to: '/user/settings' },
        { label: 'Equalizer', to: '/user/settings/equalizer' },
        { label: 'Sessions', to: '/user/settings/sessions' },
        { label: 'Passkeys', to: '/user/settings/passkeys' },
      ],
    }
  ];

  const menu = [...librariesMenu, ...staticMenu];

  return (
    <Box className={styles.sidebar}>
      <Flex align="center" justify="center">
        <Brand/>
      </Flex>

      <ScrollArea>
        <Box className={styles.menuContainer}>
          <NavLink to="/" className={styles.homeLink}>
            <Flex align="center" gap="2">
              <Iconify icon="heroicons:home" width="24" height="24"/>
              <Text>Home</Text>
            </Flex>
          </NavLink>

          {menu.map((section: MenuSection, index: number) => (
            <Box key={index} className={styles.menuSection}>
              <Text className={styles.sectionTitle}>
                <Flex align="center" gap="1">
                  <Iconify icon={section.iconName} width="20" height="20"/>
                  {section.label}
                </Flex>
              </Text>
              <Box className={styles.linksList}>
                {section.links.map((link: MenuLink, linkIndex: number) => (
                  link.to ? (
                    <NavLink
                      key={linkIndex}
                      to={link.to}
                      className={styles.menuLink}
                      activeClassName={styles.activeLink}
                    >
                      {link.label}
                    </NavLink>
                  ) : link.href ? (
                    <a
                      key={linkIndex}
                      href={link.href}
                      className={styles.menuLink}
                    >
                      {link.label}
                    </a>
                  ) : null
                ))}
              </Box>
            </Box>
          ))}
        </Box>
      </ScrollArea>
    </Box>
  );
}