import { SongResource } from '@/app/libs/api-client/gen/models';

/**
 * Form data structure for song editing
 * All fields use camelCase to match API contract
 */
export interface SongFormData extends Record<string, unknown> {
  // Basic Info
  title: string;
  track?: number;
  disc?: number;
  year?: number;
  explicit?: boolean;

  // Content
  lyrics?: string;
  comment?: string;

  // External IDs
  mbid?: string;
  discogsId?: number;
  spotifyId?: string;

  // Relations
  genres: string[];

  // Metadata
  lockedFields?: string[];
}

/**
 * Props for SongEditor component
 */
export interface SongEditorProps {
  song?: SongResource;
  librarySlug: string;
  onSubmit: (data: SongFormData) => void;
  onCancel?: () => void;
  onSync?: () => void;
  onMetadataApplied?: () => void;
}
