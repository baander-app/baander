import { useLibraryServiceLibrariesIndex } from '@/api-client/queries';
import { LibraryMusicNav } from '@/layouts/root-layout/library-nav/library-music-nav.tsx';

export function LibraryNav() {
  const {data} = useLibraryServiceLibrariesIndex();

  return (
    <>
      {data && data.data.map(item => (<LibraryMusicNav key={item.slug} slug={item.slug} />))}
    </>
  )
}