import { LibraryResource } from '@/api-client/requests';
import { LibraryType } from '@/models/library-type.ts';
import { IconMovie } from '@tabler/icons-react';
import { NavLink } from '@/ui/nav-link.tsx';


export interface LibraryNavProps {
  library: LibraryResource;
}

export function LibraryNav({library}: LibraryNavProps) {
  return (
    <NavLink
      to={`${library.slug}-${library.type}`}
      label={library.name}
      leftSection={ <IconMovie size="1rem" stroke={1.2} /> }
    >
      {library.type === LibraryType.Music && (
        <SongNavLinks />
      )}
      {library.type === LibraryType.Movie && (
        <MovieNavLinks slug={library.slug} />
      )}
      <NavLink to="#" label="Genres"/>
      <NavLink to="#" label="Playlists">
        <NavLink to="#" label="Playlist 1" />
        <NavLink to="#" label="Playlist 2" />
        <NavLink to="#" label="Playlist 3" />
      </NavLink>
    </NavLink>
  )
}

export interface MovieNavLinksProps {
  slug: string;
}
function MovieNavLinks({slug}: MovieNavLinksProps) {
  return (
    <>
      <NavLink to={`/library/${slug}`} label="Overview" />
    </>
  )
}

function SongNavLinks() {
  return (
    <>
      <NavLink to="#" label="Albums" />
      <NavLink to="#" label="Songs" />
    </>
  )
}