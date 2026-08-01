import type { MediaSidebarSchema } from './types'

export const podcastsSidebar: MediaSidebarSchema = {
  mediaType: 'podcasts',
  sections: [
    {
      id: 'podcasts-quick-jump',
      label: 'Quick Jump',
      type: 'navigation',
      items: [
        { id: 'podcasts-home', type: 'page_link', label: 'Home', icon: 'home', config: { route: '/podcasts' } },
        { id: 'podcasts-browse', type: 'page_link', label: 'Browse', icon: '_disc', config: { route: '/podcasts/browse' } },
      ],
    },
    {
      id: 'podcasts-library',
      label: 'Library',
      type: 'navigation',
      items: [
        { id: 'podcasts-items', type: 'page_link', label: 'Podcasts', icon: '_disc', config: { route: '/podcasts' } },
        { id: 'podcasts-episodes', type: 'page_link', label: 'Episodes', icon: 'play', config: { route: '/podcasts/episodes' } },
      ],
    },
    {
      id: 'podcasts-collections',
      label: 'Collections',
      type: 'navigation',
      items: [
        { id: 'podcasts-subscriptions', type: 'page_link', label: 'Subscriptions', icon: 'list-music', config: { route: '/podcasts/subscriptions' } },
      ],
    },
    {
      id: 'podcasts-discover',
      label: 'Discover',
      type: 'navigation',
      items: [
        { id: 'podcasts-discover', type: 'page_link', label: 'Discover', icon: 'sparkles', config: { route: '/podcasts/discover' } },
        { id: 'podcasts-trending', type: 'page_link', label: 'Trending', icon: 'trending-up', config: { route: '/podcasts/trending' } },
      ],
    },
  ],
}
