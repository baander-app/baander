import { FormFieldConfig, FormSectionConfig } from '@/app/ui/form';
import { SongFormData } from './song-editor.types';

/**
 * Field configuration for song editor
 * Defines all editable fields with their types and options
 */
export const songFieldConfig: FormFieldConfig<SongFormData>[] = [
  // Basic Info & Positioning
  {
    name: 'title',
    label: 'Title',
    type: 'text',
    placeholder: 'Song title',
    required: true,
  },
  {
    name: 'track',
    label: 'Track',
    type: 'text',
    inputType: 'number',
    placeholder: 'Track number',
    description: 'Track number on the album',
  },
  {
    name: 'disc',
    label: 'Disc',
    type: 'text',
    inputType: 'number',
    placeholder: 'Disc number',
    description: 'Disc number in the album',
  },
  {
    name: 'year',
    label: 'Year',
    type: 'text',
    inputType: 'number',
    placeholder: 'Release year',
  },
  {
    name: 'explicit',
    label: 'Explicit',
    type: 'checkbox',
    description: 'Mark as explicit content',
  },

  // Content
  {
    name: 'lyrics',
    label: 'Lyrics',
    type: 'textarea',
    placeholder: 'Song lyrics',
    description: 'Full lyrics text',
  },
  {
    name: 'comment',
    label: 'Comment',
    type: 'textarea',
    placeholder: 'Notes or comments about this song',
    description: 'Private notes for reference (not displayed to users)',
  },

  // Read-only path (for reference)
  {
    name: 'path',
    label: 'File Path',
    type: 'text',
    disabled: true,
    description: 'Filesystem path (read-only)',
  },

  // External IDs
  {
    name: 'mbid',
    label: 'MusicBrainz ID',
    type: 'text',
    placeholder: 'MusicBrainz ID (UUID)',
    description: 'Unique identifier from MusicBrainz database',
  },
  {
    name: 'discogsId',
    label: 'Discogs ID',
    type: 'text',
    inputType: 'number',
    placeholder: 'Discogs release ID',
    description: 'Release identifier from Discogs database',
  },
  {
    name: 'spotifyId',
    label: 'Spotify ID',
    type: 'text',
    placeholder: 'Spotify track ID',
    description: 'Track identifier from Spotify',
  },

  // Genres (multi-select)
  {
    name: 'genres',
    label: 'Genres',
    type: 'multiselect',
    placeholder: 'Select genres',
    description: 'Musical genres associated with this song',
    // options will be provided at runtime via genre data
  },
];

/**
 * Form section configuration
 * Groups fields into logical sections for better UX
 */
export const songFormSections: FormSectionConfig<SongFormData>[] = [
  {
    title: 'Details',
    fields: [
      'title',
      'track',
      'disc',
      'year',
      'explicit',
      'lyrics',
      'comment',
      'path',
      'genres',
    ] as const,
  },
  {
    title: 'External IDs',
    fields: ['mbid', 'discogsId', 'spotifyId'] as const,
  },
];
