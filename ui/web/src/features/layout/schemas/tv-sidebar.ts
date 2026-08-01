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
        { id: 'tv-seasons', type: 'page_link', label: 'Seasons', icon: 'layers', config: { route: '/tv/seasons' } },
        { id: 'tv-episodes', type: 'page_link', label: 'Episodes', icon: 'play', config: { route: '/tv/episodes' } },
      ],
    },
    {
      id: 'tv-collections',
      label: 'Collections',
      type: 'navigation',
      items: [
        { id: 'tv-watchlists', type: 'page_link', label: 'Watchlists', icon: 'list-music', config: { route: '/tv/watchlists' } },
        { id: 'tv-favorites', type: 'page_link', label: 'Favorites', icon: 'star', config: { route: '/tv/favorites' } },
      ],
    },
    {
      id: 'tv-discover',
      label: 'Discover',
      type: 'navigation',
      items: [
        { id: 'tv-continue-watching', type: 'page_link', label: 'Continue Watching', icon: 'play', config: { route: '/tv/continue-watching' } },
        { id: 'tv-recommended', type: 'page_link', label: 'Recommended', icon: 'sparkles', config: { route: '/tv/recommended' } },
      ],
    },
  ],
}
