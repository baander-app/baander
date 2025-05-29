import { Box, Button, Dialog, Flex, ScrollArea, Text } from '@radix-ui/themes';
import { NavLink } from '@/ui/nav-link';
import { lazyImport } from '@/utils/lazy-import';
import { Iconify } from '@/ui/icons/iconify';
import styles from './root-menu.module.scss';
import { ReactNode, useMemo } from 'react';
import { useLibraryServiceGetApiLibraries, usePlaylistServiceGetApiPlaylists } from '@/api-client/queries';
import { CreatePlaylist } from '@/modules/library-music/components/create-playlist/create-playlist.tsx';
import { LibraryResource, PlaylistResource } from '@/api-client/requests/types.gen';

const { Brand } = lazyImport(() => import('@/ui/brand/Brand'), 'Brand');

interface MenuLink {
  label: string;
  to?: string;
  href?: string;
  isSubsectionHeader?: boolean;
  isInnerSectionItem?: boolean;
  isDeepestLevelItem?: boolean;
  isTextOnly?: boolean;
}

interface MenuSection {
  label: string;
  iconName: string;
  rightSide?: ReactNode;
  links: MenuLink[];
}

export function RootMenu() {
  const { data: libraryData } = useLibraryServiceGetApiLibraries();
  const { data: playlistData } = usePlaylistServiceGetApiPlaylists();

  const musicLibraries: LibraryResource[] = useMemo(() => libraryData?.data.filter(library => library.type === 'music') ?? [], [libraryData]);
  const movieLibraries: LibraryResource[] = useMemo(() => libraryData?.data.filter(library => library.type === 'movie') ?? [], [libraryData]);
  const playlists: PlaylistResource[] = useMemo(() => playlistData?.data ?? [], [playlistData]);

  const librariesMenu: MenuSection[] = useMemo(() => {
    const sections: MenuSection[] = [];

    const musicSection: MenuSection = {
      label: 'Music',
      iconName: 'ion:musical-notes',
      links: [],
      rightSide: (
        <>
          <Dialog.Root>
            <Dialog.Trigger>
              <Button
                ml="2"
                variant="ghost"
              >New</Button>
            </Dialog.Trigger>
            <Dialog.Content>
              <Dialog.Title>Create Playlist</Dialog.Title>
              <Dialog.Description></Dialog.Description>

              <CreatePlaylist />
            </Dialog.Content>
          </Dialog.Root>
        </>
      ),
    };

    // Add music libraries as subsections
    if (musicLibraries.length > 0) {
      for (const library of musicLibraries) {
        // Add library as a subsection header
        musicSection.links.push({
          label: library.name,
          isSubsectionHeader: true,
          isTextOnly: true,
        });

        // Add library links as subsection items
        musicSection.links.push(
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
          }
        );
      }
    }

    // Add Playlists subsection
    musicSection.links.push({
      label: 'Playlists',
      isSubsectionHeader: true,
      isTextOnly: true,
    });

    // Add individual playlists
    // Group playlists by first letter for demonstration purposes
    const playlistsByLetter = playlists.reduce<Record<string, PlaylistResource[]>>((acc, playlist) => {
      const firstLetter = playlist.name.charAt(0).toUpperCase();
      if (!acc[firstLetter]) {
        acc[firstLetter] = [];
      }
      acc[firstLetter].push(playlist);
      return acc;
    }, {});

    // Add playlist groups as inner sections
    Object.keys(playlistsByLetter).sort().forEach(letter => {
      // Add letter as an inner section header
      if (playlistsByLetter[letter].length > 1) {
        musicSection.links.push({
          label: `${letter}`,
          isSubsectionHeader: true,
          isInnerSectionItem: true,
          isTextOnly: true,
        });

        // Group playlists by length for demonstration of deepest level
        const playlistsByLength = playlistsByLetter[letter].reduce<Record<string, PlaylistResource[]>>((acc, playlist) => {
          // Group by name length (short, medium, long) for demonstration
          let lengthCategory = 'Medium';
          if (playlist.name.length < 6) {
            lengthCategory = 'Short';
          } else if (playlist.name.length > 10) {
            lengthCategory = 'Long';
          }

          if (!acc[lengthCategory]) {
            acc[lengthCategory] = [];
          }
          acc[lengthCategory].push(playlist);
          return acc;
        }, {});

        // Add length categories as groups
        Object.keys(playlistsByLength).sort().forEach(lengthCategory => {
          if (playlistsByLength[lengthCategory].length > 1) {
            // Add length category as a group header
            musicSection.links.push({
              label: `${lengthCategory} Names`,
              isInnerSectionItem: true,
              isTextOnly: true,
            });

            // Add playlists in this group as deepest level items
            playlistsByLength[lengthCategory].forEach(playlist => {
              musicSection.links.push({
                label: playlist.name,
                to: `/playlists/music/${playlist.id}`,
                isDeepestLevelItem: true,
              });
            });
          } else {
            // If there's only one playlist in this length category, don't create a group
            playlistsByLength[lengthCategory].forEach(playlist => {
              musicSection.links.push({
                label: playlist.name,
                to: `/playlists/music/${playlist.id}`,
                isInnerSectionItem: true,
              });
            });
          }
        });
      } else {
        // If there's only one playlist with this letter, don't create a group
        playlistsByLetter[letter].forEach(playlist => {
          musicSection.links.push({
            label: playlist.name,
            to: `/playlists/music/${playlist.id}`,
          });
        });
      }
    });

    // Add the music section to the menu
    sections.push(musicSection);

    // Add movie libraries
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
  }, [musicLibraries, playlists]);

  const staticMenu: MenuSection[] = [
    {
      label: 'Navigation',
      iconName: 'heroicons:home',
      links: [
        { label: 'Dashboard', to: '/dashboard/home' },
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
    },
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

                  {section?.rightSide && (
                    <>
                      {section.rightSide}
                    </>
                  )}
                </Flex>
              </Text>
              <Box className={styles.linksList}>
                {section.links.map((link: MenuLink, linkIndex: number) => {
                  const isSubsectionHeader = link.isSubsectionHeader === true;
                  const isInnerSectionItem = link.isInnerSectionItem === true;
                  const isDeepestLevelItem = link.isDeepestLevelItem === true;
                  let isPartOfSubsection = false;
                  if (!isSubsectionHeader && !isInnerSectionItem && !isDeepestLevelItem) {
                    // Find the last subsection header before this link
                    for (let i = linkIndex - 1; i >= 0; i--) {
                      if (section.links[i].isSubsectionHeader) {
                        // If we find a subsection header, this link is part of that subsection
                        isPartOfSubsection = true;
                        break;
                      }
                    }
                  }

                  // Create an array of class names and filter out any falsy values
                  const classNames = [
                    styles.menuLink,
                    isSubsectionHeader && !isInnerSectionItem && styles.subsectionHeader,
                    isSubsectionHeader && isInnerSectionItem && styles.innerSectionHeader,
                    isPartOfSubsection && styles.subsectionItem,
                    isInnerSectionItem && !isSubsectionHeader && !isDeepestLevelItem && styles.innerSectionItem,
                    isDeepestLevelItem && styles.deepestLevelItem
                  ].filter(Boolean);

                  // Join the class names with a space
                  const linkClassName = classNames.join(' ');

                  if (link.isTextOnly) {
                    return (
                      <Text
                        key={linkIndex}
                        className={linkClassName}
                      >
                        {link.label}
                      </Text>
                    );
                  }

                  // Otherwise, render it as a link
                  return link.to ? (
                    <NavLink
                      key={linkIndex}
                      to={link.to}
                      className={linkClassName}
                      activeClassName={styles.activeLink}
                    >
                      {link.label}
                    </NavLink>
                  ) : link.href ? (
                    <a
                      key={linkIndex}
                      href={link.href}
                      className={linkClassName}
                    >
                      {link.label}
                    </a>
                  ) : null;
                })}
              </Box>
            </Box>
          ))}
        </Box>
      </ScrollArea>
    </Box>
  );
}
