export type { SidebarSection, MediaSidebarSchema } from './types'

import type { MediaType } from '../stores/media-mode-store'
import type { MediaSidebarSchema } from './types'
import { musicSidebar } from './music-sidebar'
import { moviesSidebar } from './movies-sidebar'
import { tvSidebar } from './tv-sidebar'
import { podcastsSidebar } from './podcasts-sidebar'
import { concertsSidebar } from './concerts-sidebar'
import { ebooksSidebar } from './ebooks-sidebar'

export const ALL_SCHEMAS: Record<MediaType, MediaSidebarSchema> = {
  music: musicSidebar,
  movies: moviesSidebar,
  tv: tvSidebar,
  podcasts: podcastsSidebar,
  concerts: concertsSidebar,
  ebooks: ebooksSidebar,
}
