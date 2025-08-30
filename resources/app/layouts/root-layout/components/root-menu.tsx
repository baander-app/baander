import { Box, Button, ContextMenu, Dialog, Flex, ScrollArea, Text } from '@radix-ui/themes';
import { NavLink } from '@/ui/nav-link';
import { lazyImport } from '@/utils/lazy-import';
import { Iconify } from '@/ui/icons/iconify';
import styles from './root-menu.module.scss';
import { ReactNode, useMemo } from 'react';
import { CreatePlaylist } from '@/modules/library-music-playlists/components/create-playlist/create-playlist.tsx';
import { CreateSmartPlaylist } from '@/modules/library-music-playlists/components/create-smart-playlist/create-smart-playlist.tsx';
import {
  PlaylistLayoutContextMenu
} from '@/modules/library-music-playlists/components/context-menu/playlist-layout-context-menu/playlist-layout-context-menu.tsx';
import { useLibrariesIndex } from '@/libs/api-client/gen/endpoints/library/library.ts';
import { usePlaylistIndex } from '@/libs/api-client/gen/endpoints/playlist/playlist.ts';
import { LibraryResource, PlaylistResource } from '@/libs/api-client/gen/models';

const { Brand } = lazyImport(() => import('@/ui/brand/Brand'), 'Brand');

interface MenuLink {
  label: string;
  to?: string;
  href?: string;
  isSubsectionHeader?: boolean;
  isInnerSectionItem?: boolean;
  isDeepestLevelItem?: boolean;
  isTextOnly?: boolean;
  type?: 'playlist';
}

interface MenuSection {
  label: string;
  iconName: string;
  rightSide?: ReactNode;
  links: MenuLink[];
}

export function RootMenu() {
  const { data: libraryData } = useLibrariesIndex();
  const { data: playlistData } = usePlaylistIndex();

  const musicLibraries: LibraryResource[] = useMemo(() => libraryData?.data?.filter(library => library?.type === 'music') ?? [], [libraryData]);
  const movieLibraries: LibraryResource[] = useMemo(() => libraryData?.data?.filter(library => library?.type === 'movie') ?? [], [libraryData]);
  const playlists: PlaylistResource[] = useMemo(() => playlistData?.data ?? [], [playlistData]);

  const librariesMenu: MenuSection[] = useMemo(() => {
    const sections: MenuSection[] = [];

    const musicSection: MenuSection = {
      label: 'Music',
      iconName: 'ion:musical-notes',
      links: [],
      rightSide: (
        <Flex gap="1">
          <Dialog.Root>
            <Dialog.Trigger>
              <Button
                size="1"
                variant="ghost"
              >New</Button>
            </Dialog.Trigger>
            <Dialog.Content>
              <Dialog.Title>Create Playlist</Dialog.Title>
              <Dialog.Description></Dialog.Description>

              <CreatePlaylist />
            </Dialog.Content>
          </Dialog.Root>

          <Dialog.Root>
            <Dialog.Trigger>
              <Button
                size="1"
                variant="ghost"
              >Smart</Button>
            </Dialog.Trigger>
            <Dialog.Content>
              <Dialog.Title>Create Smart Playlist</Dialog.Title>
              <Dialog.Description>Smart playlists automatically update based on rules you define.</Dialog.Description>

              <CreateSmartPlaylist />
            </Dialog.Content>
          </Dialog.Root>
        </Flex>
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

    // Add individual playlists directly by title
    playlists.forEach(playlist => {
      musicSection.links.push({
        label: playlist.name,
        to: `/playlists/music/${playlist.publicId}`,
        type: 'playlist',
      });
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
          <NavLink
            to="/"
            className={styles.homeLink}
          >
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
                        aria-disabled
                      >
                        {link.label}
                      </Text>
                    );
                  }

                  if (link.to && link.type === 'playlist') {
                    return (
                      <ContextMenu.Root key={linkIndex}>
                        <ContextMenu.Trigger>
                          <NavLink
                            key={linkIndex}
                            to={link.to}
                            className={linkClassName}
                            activeClassName={styles.activeLink}
                          >
                            {link.label}
                          </NavLink>
                        </ContextMenu.Trigger>
                        <PlaylistLayoutContextMenu id={link.to.split('/').at(-1)!} />
                      </ContextMenu.Root>
                    )
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
