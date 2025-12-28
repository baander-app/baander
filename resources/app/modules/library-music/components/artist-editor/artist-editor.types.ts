import { ArtistResource } from '@/app/libs/api-client/gen/models';

/**
 * Form data structure for artist editing
 * All fields use camelCase to match API contract
 */
export interface ArtistFormData extends Record<string, unknown> {
  // Basic Info
  name: string;
  disambiguation?: string;

  // Metadata
  type?: string;
  country?: string;
  gender?: string;
  sortName?: string;

  // External IDs
  mbid?: string;
  discogsId?: number;
  spotifyId?: string;

  // Biography
  biography?: string;

  // Life Span
  lifeSpanBegin?: string;
  lifeSpanEnd?: string;

  // Metadata
  lockedFields?: string[];
}

/**
 * Props for ArtistEditor component
 */
export interface ArtistEditorProps {
  artist?: ArtistResource;
  librarySlug: string;
  onSubmit: (data: ArtistFormData) => void;
  onCancel?: () => void;
  onSync?: () => void;
  onMetadataApplied?: () => void;
}
