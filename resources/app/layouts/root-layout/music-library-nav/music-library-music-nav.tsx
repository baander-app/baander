import { NavLink } from '@/components/nav-link.tsx';
import { NavLink as MantineNavLink } from '@mantine/core';
import { LibraryResource } from '@/api-client/requests';
import { IconMusic } from '@tabler/icons-react';

export interface LibraryMusicNavProps {
  library: LibraryResource;
}

export function MusicLibraryMusicNav({ library }: LibraryMusicNavProps) {
  return (
    <MantineNavLink
      label={library.name}
      href={`/library/${library.slug}`}
      leftSection={<IconMusic size="1rem" stroke={1.2} />}
    >
      <NavLink variant="light" to={`/library/${library.slug}/artists`} label="Artists"/>
      <NavLink variant="light" to={`/library/${library.slug}/albums`} label="Albums"/>
      <NavLink variant="light" to={`/library/${library.slug}/songs`} label="Songs"/>
      <NavLink variant="light" to={`/library/${library.slug}/genres`} label="Genres"/>
    </MantineNavLink>
  );
}