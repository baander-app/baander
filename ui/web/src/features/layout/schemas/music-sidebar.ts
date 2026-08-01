import type { MediaSidebarSchema } from './types'

export const musicSidebar: MediaSidebarSchema = {
  mediaType: 'music',
  sections: [
    {
      id: 'music-quick-jump',
      label: 'Quick Jump',
      type: 'navigation',
      items: [
        { id: 'music-home', type: 'page_link', label: 'Home', icon: 'home', config: { route: '/music' } },
        { id: 'music-browse', type: 'page_link', label: 'Browse', icon: '_disc', config: { route: '/music/browse' } },
      ],
    },
    {
      id: 'music-library',
      label: 'Library',
      type: 'navigation',
      items: [
        { id: 'music-albums', type: 'page_link', label: 'Albums', icon: '_disc', config: { route: '/music/albums' } },
        { id: 'music-artists', type: 'page_link', label: 'Artists', icon: 'mic-2', config: { route: '/music/artists' } },
        { id: 'music-songs', type: 'page_link', label: 'Songs', icon: 'music', config: { route: '/music/songs' } },
        { id: 'music-genres', type: 'page_link', label: 'Genres', icon: 'tag', config: { route: '/music/genres' } },
      ],
    },
    {
      id: 'music-collections',
      label: 'Collections',
      type: 'navigation',
      items: [
        { id: 'music-playlists', type: 'page_link', label: 'Playlists', icon: 'list-music', config: { route: '/music/playlists' } },
        { id: 'music-favorites', type: 'page_link', label: 'Favorites', icon: 'star', config: { route: '/music/favorites' } },
      ],
    },
    {
      id: 'music-discover',
      label: 'Discover',
      type: 'navigation',
      items: [
        { id: 'music-radio', type: 'page_link', label: 'Radio', icon: 'radio', config: { route: '/music/radio' } },
        { id: 'music-recommended', type: 'page_link', label: 'Recommended', icon: 'sparkles', config: { route: '/music/recommended' } },
      ],
    },
  ],
}
