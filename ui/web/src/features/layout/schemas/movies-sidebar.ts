import type { MediaSidebarSchema } from './types'

export const moviesSidebar: MediaSidebarSchema = {
  mediaType: 'movies',
  sections: [
    {
      id: 'movies-quick-jump',
      label: 'Quick Jump',
      type: 'navigation',
      items: [
        { id: 'movies-home', type: 'page_link', label: 'Home', icon: 'home', config: { route: '/movies' } },
        { id: 'movies-browse', type: 'page_link', label: 'Browse', icon: '_disc', config: { route: '/movies/browse' } },
      ],
    },
    {
      id: 'movies-library',
      label: 'Library',
      type: 'navigation',
      items: [
        { id: 'movies-items', type: 'page_link', label: 'Movies', icon: '_disc', config: { route: '/movies' } },
        { id: 'movies-directors', type: 'page_link', label: 'Directors', icon: 'mic-2', config: { route: '/movies/directors' } },
        { id: 'movies-genres', type: 'page_link', label: 'Genres', icon: 'tag', config: { route: '/movies/genres' } },
      ],
    },
    {
      id: 'movies-collections',
      label: 'Collections',
      type: 'navigation',
      items: [
        { id: 'movies-watchlists', type: 'page_link', label: 'Watchlists', icon: 'list-music', config: { route: '/movies/watchlists' } },
        { id: 'movies-favorites', type: 'page_link', label: 'Favorites', icon: 'star', config: { route: '/movies/favorites' } },
      ],
    },
    {
      id: 'movies-discover',
      label: 'Discover',
      type: 'navigation',
      items: [
        { id: 'movies-recommended', type: 'page_link', label: 'Recommended', icon: 'sparkles', config: { route: '/movies/recommended' } },
        { id: 'movies-recently-watched', type: 'page_link', label: 'Recently Watched', icon: 'clock', config: { route: '/movies/recent' } },
      ],
    },
  ],
}
