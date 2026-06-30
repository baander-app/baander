import type { MediaSidebarSchema } from './types'

export const ebooksSidebar: MediaSidebarSchema = {
  mediaType: 'ebooks',
  sections: [
    {
      id: 'ebooks-quick-jump',
      label: 'Quick Jump',
      type: 'navigation',
      items: [
        { id: 'ebooks-home', type: 'page_link', label: 'Home', icon: 'home', config: { route: '/ebooks' } },
        { id: 'ebooks-browse', type: 'page_link', label: 'Browse', icon: '_disc', config: { route: '/ebooks/browse' } },
      ],
    },
    {
      id: 'ebooks-library',
      label: 'Library',
      type: 'navigation',
      items: [
        { id: 'ebooks-items', type: 'page_link', label: 'Books', icon: 'book', config: { route: '/ebooks' } },
        { id: 'ebooks-authors', type: 'page_link', label: 'Authors', icon: 'mic-2', config: { route: '/ebooks/authors' } },
        { id: 'ebooks-series', type: 'page_link', label: 'Series', icon: 'layers', config: { route: '/ebooks/series' } },
      ],
    },
    {
      id: 'ebooks-collections',
      label: 'Collections',
      type: 'navigation',
      items: [
        { id: 'ebooks-shelves', type: 'page_link', label: 'Shelves', icon: 'list-music', config: { route: '/ebooks/shelves' } },
        { id: 'ebooks-reading-lists', type: 'page_link', label: 'Reading Lists', icon: 'book-open', config: { route: '/ebooks/reading-lists' } },
      ],
    },
    {
      id: 'ebooks-discover',
      label: 'Discover',
      type: 'navigation',
      items: [
        { id: 'ebooks-recommended', type: 'page_link', label: 'Recommended', icon: 'sparkles', config: { route: '/ebooks/recommended' } },
      ],
    },
  ],
}
