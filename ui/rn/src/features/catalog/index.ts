/**
 * Catalog feature exports.
 */

// Stores
export { useViewModeStore, type ViewMode, type ViewModeState } from './stores/view-mode-store';
export { useSelectionStore, type CatalogItemType, type SelectionState } from './stores/selection-store';

// Hooks
export { useAlbums, type UseAlbumsParams, type UseAlbumsResult } from './hooks/useAlbums';
export { useArtists, type UseArtistsParams, type UseArtistsResult } from './hooks/useArtists';
export { useTracks, type UseTracksResult } from './hooks/useTracks';
export { useGenres, type UseGenresResult } from './hooks/useGenres';
export { useSearch, type UseSearchParams, type UseSearchResult } from './hooks/useSearch';

// API
export {
  getAlbums,
  getAlbum,
  getAlbumTracks,
  getArtists,
  getArtist,
  getArtistAlbums,
  getGenres,
  search,
  type Album,
  type Artist,
  type Song,
  type Genre,
  type ApiResponse,
  type SearchParams,
  type SearchResult,
} from './api/catalog-api';
