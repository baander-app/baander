import { NavLink } from '@/components/nav-link.tsx';

export interface LibraryMusicNavProps {
  slug: string;
}
export function LibraryMusicNav({slug}: LibraryMusicNavProps) {
  return (
    <>
      <NavLink variant="light" to={`/library/${slug}/artists`} label="Artists" />
      <NavLink variant="light" to={`/library/${slug}/albums`} label="Albums" />
      <NavLink variant="light" to={`/library/${slug}/songs`} label="Songs" />
      <NavLink variant="light" to={`/library/${slug}/genres`} label="Genres" />
    </>
  )
}