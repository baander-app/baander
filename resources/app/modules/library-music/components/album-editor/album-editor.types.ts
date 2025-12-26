import { AlbumResource } from '@/app/libs/api-client/gen/models';

/**
 * Form data structure for album editing
 * All fields use camelCase to match API contract
 */
export interface AlbumFormData extends Record<string, unknown> {
  // Basic Info
  title: string;
  type?: string;
  year?: number;
  disambiguation?: string;

  // External IDs
  mbid?: string;
  discogsId?: number;
  spotifyId?: string;

  // Release Details
  label?: string;
  catalogNumber?: string;
  barcode?: string;
  country?: string;
  language?: string;

  // Notes
  annotation?: string;

  // Relations
  genres: string[];

  // Metadata
  lockedFields?: string[];
}

/**
 * Props for AlbumEditor component
 */
export interface AlbumEditorProps {
  album?: AlbumResource;
  librarySlug: string;
  onSubmit: (data: AlbumFormData) => void;
  onCancel?: () => void;
  onSync?: () => void;
  onMetadataApplied?: () => void;
}
