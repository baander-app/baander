import type { MediaSidebarSchema } from './types'

export const tvSidebar: MediaSidebarSchema = {
  mediaType: 'tv',
  sections: [
    {
      id: 'tv-quick-jump',
      label: 'Quick Jump',
      type: 'navigation',
      items: [
        { id: 'tv-home', type: 'page_link', label: 'Home', icon: 'home', config: { route: '/tv' } },
        { id: 'tv-browse', type: 'page_link', label: 'Browse', icon: '_disc', config: { route: '/tv/browse' } },
      ],
    },
    {
      id: 'tv-library',
      label: 'Library',
      type: 'navigation',
      items: [
        { id: 'tv-shows', type: 'page_link', label: 'Shows', icon: '_disc', config: { route: '/tv/shows' } },
      ],
    },
    { id: 'tv-member-libraries', label: 'TV libraries', type: 'tv-libraries', items: [] },
  ],
}
