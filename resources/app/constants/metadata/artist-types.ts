/**
 * Artist type options for dropdown selects
 * Matches MusicBrainz artist type values
 */
export const ARTIST_TYPE_OPTIONS = [
  { value: 'person', label: 'Person' },
  { value: 'group', label: 'Group' },
  { value: 'orchestra', label: 'Orchestra' },
  { value: 'choir', label: 'Choir' },
  { value: 'character', label: 'Character' },
  { value: 'other', label: 'Other' },
  { value: 'undefined', label: 'Unknown' },
] as const;

export type ArtistTypeOption = typeof ARTIST_TYPE_OPTIONS[number];
