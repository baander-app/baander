import { FormFieldConfig, FormSectionConfig } from '@/app/ui/form';
import { ArtistFormData } from './artist-editor.types';

/**
 * Field configuration for artist editor
 * Defines all editable fields with their types and options
 */
export const artistFieldConfig: FormFieldConfig<ArtistFormData>[] = [
  // Basic Info
  {
    name: 'name',
    label: 'Name',
    type: 'text',
    placeholder: 'Artist name',
  },
  {
    name: 'disambiguation',
    label: 'Disambiguation',
    type: 'text',
    placeholder: 'Disambiguation (e.g., "British rock band", "American singer")',
    description: 'Helps distinguish this artist from others with similar names',
  },

  // Metadata
  {
    name: 'type',
    label: 'Type',
    type: 'select',
    placeholder: 'Select artist type',
    description: 'Whether this is a person, group, orchestra, etc.',
  },
  {
    name: 'country',
    label: 'Country',
    type: 'select',
    placeholder: 'Select country of origin',
    description: 'Country where the artist is from',
  },
  {
    name: 'gender',
    label: 'Gender',
    type: 'select',
    placeholder: 'Select gender',
  },
  {
    name: 'sortName',
    label: 'Sort Name',
    type: 'text',
    placeholder: 'Sort name (e.g., "Beatles, The")',
    description: 'Alternative name for sorting purposes',
  },
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
    placeholder: 'Discogs artist ID',
    description: 'Artist identifier from Discogs database',
  },
  {
    name: 'spotifyId',
    label: 'Spotify ID',
    type: 'text',
    placeholder: 'Spotify artist ID',
    description: 'Artist identifier from Spotify',
  },

  // Biography
  {
    name: 'biography',
    label: 'Biography',
    type: 'textarea',
    placeholder: 'Artist biography and career information',
    description: 'Detailed information about the artist',
  },
  {
    name: 'lifeSpanBegin',
    label: 'Career Begin',
    type: 'text',
    placeholder: 'YYYY-MM-DD or YYYY',
    description: 'When the artist began their career',
  },
  {
    name: 'lifeSpanEnd',
    label: 'Career End',
    type: 'text',
    placeholder: 'YYYY-MM-DD or YYYY',
    description: 'When the artist ended their career (if applicable)',
  },
];

/**
 * Form section configuration
 * Groups fields into logical sections for better UX
 */
export const artistFormSections: FormSectionConfig<ArtistFormData>[] = [
  {
    title: 'Basic Info',
    fields: ['name', 'disambiguation'] as const,
  },
  {
    title: 'Biography',
    fields: ['biography', 'lifeSpanBegin', 'lifeSpanEnd'] as const,
  },
  {
    title: 'Metadata',
    fields: ['type', 'country', 'gender', 'sortName', 'mbid', 'discogsId', 'spotifyId'] as const,
  },
];
