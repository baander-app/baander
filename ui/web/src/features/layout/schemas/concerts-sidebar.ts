import type { MediaSidebarSchema } from './types'

export const concertsSidebar: MediaSidebarSchema = {
  mediaType: 'concerts',
  sections: [
    {
      id: 'concerts-quick-jump',
      label: 'Quick Jump',
      type: 'navigation',
      items: [
        { id: 'concerts-home', type: 'page_link', label: 'Home', icon: 'home', config: { route: '/concerts' } },
        { id: 'concerts-browse', type: 'page_link', label: 'Browse', icon: '_disc', config: { route: '/concerts/browse' } },
      ],
    },
    {
      id: 'concerts-library',
      label: 'Library',
      type: 'navigation',
      items: [
        { id: 'concerts-items', type: 'page_link', label: 'Concerts', icon: '_disc', config: { route: '/concerts' } },
        { id: 'concerts-venues', type: 'page_link', label: 'Venues', icon: 'map-pin', config: { route: '/concerts/venues' } },
        { id: 'concerts-artists', type: 'page_link', label: 'Artists', icon: 'mic-2', config: { route: '/concerts/artists' } },
      ],
    },
    {
      id: 'concerts-collections',
      label: 'Collections',
      type: 'navigation',
      items: [
        { id: 'concerts-favorites', type: 'page_link', label: 'Favorites', icon: 'star', config: { route: '/concerts/favorites' } },
      ],
    },
    {
      id: 'concerts-discover',
      label: 'Discover',
      type: 'navigation',
      items: [
        { id: 'concerts-nearby', type: 'page_link', label: 'Nearby', icon: 'map-pin', config: { route: '/concerts/nearby' } },
        { id: 'concerts-recommended', type: 'page_link', label: 'Recommended', icon: 'sparkles', config: { route: '/concerts/recommended' } },
      ],
    },
  ],
}
