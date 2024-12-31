import { MusicLibraryMusicNav } from '@/layouts/root-layout/components/music-library-music-nav.tsx';
import { LibraryResource } from '@/api-client/requests';

export interface MusicLibraryNavProps {
  libraries: LibraryResource[];
}
export function MusicLibraryNav({libraries}: MusicLibraryNavProps) {

  return (
    <>
      {libraries.map((library) => (<MusicLibraryMusicNav key={library.slug} library={library}/>))}
    </>
  )
}