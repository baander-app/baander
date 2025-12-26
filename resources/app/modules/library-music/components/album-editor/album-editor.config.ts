import { FormFieldConfig, FormSectionConfig } from '@/app/ui/form';
import { AlbumFormData } from './album-editor.types';

/**
 * Field configuration for album editor
 * Defines all editable fields with their types and options
 */
export const albumFieldConfig: FormFieldConfig<AlbumFormData>[] = [
  // Basic Info
  {
    name: 'title',
    label: 'Title',
    type: 'text',
    placeholder: 'Album title',
  },
  {
    name: 'type',
    label: 'Type',
    type: 'select',
    placeholder: 'Select album type',
    // options will be provided at runtime via ALBUM_TYPE_OPTIONS
  },
  {
    name: 'year',
    label: 'Year',
    type: 'text',
    inputType: 'number',
    placeholder: 'Release year',
  },
  {
    name: 'disambiguation',
    label: 'Disambiguation',
    type: 'text',
    placeholder: 'Disambiguation (e.g., "Deluxe Edition", "Remastered")',
    description: 'Helps distinguish this album from others with similar titles',
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
    placeholder: 'Spotify album ID',
    description: 'Album identifier from Spotify',
  },

  // Release Details
  {
    name: 'label',
    label: 'Label',
    type: 'text',
    placeholder: 'Record label name',
    description: 'The record company that released this album',
  },
  {
    name: 'catalogNumber',
    label: 'Catalog Number',
    type: 'text',
    placeholder: 'Catalog number (e.g., "ABC-123")',
    description: 'Release catalog number assigned by the label',
  },
  {
    name: 'barcode',
    label: 'Barcode',
    type: 'text',
    placeholder: 'UPC/EAN barcode',
    description: 'Product barcode (UPC or EAN code)',
  },
  {
    name: 'country',
    label: 'Country',
    type: 'select',
    placeholder: 'Select country of release',
    description: 'Country where this album was released',
    // options will be provided at runtime via COUNTRY_OPTIONS
  },
  {
    name: 'language',
    label: 'Language',
    type: 'select',
    placeholder: 'Select primary language',
    description: 'Primary language of the album content',
    // options will be provided at runtime via LANGUAGE_OPTIONS
  },

  // Notes
  {
    name: 'annotation',
    label: 'Annotation',
    type: 'textarea',
    placeholder: 'Additional notes or comments about this album',
    description: 'Private notes for reference (not displayed to users)',
  },

  // Genres (multi-select)
  {
    name: 'genres',
    label: 'Genres',
    type: 'multiselect',
    placeholder: 'Select genres',
    description: 'Musical genres associated with this album',
    // options will be provided at runtime via genre data
  },
];

/**
 * Form section configuration
 * Groups fields into logical sections for better UX
 */
export const albumFormSections: FormSectionConfig<AlbumFormData>[] = [
  {
    title: 'Basic Info',
    fields: ['title', 'type', 'year', 'disambiguation'] as const,
  },
  {
    title: 'External IDs',
    fields: ['mbid', 'discogsId', 'spotifyId'] as const,
  },
  {
    title: 'Release Details',
    fields: ['label', 'catalogNumber', 'barcode', 'country', 'language'] as const,
  },
  {
    title: 'Notes',
    fields: ['annotation', 'genres'] as const,
  },
];
