/**
 * Album type options for dropdown selects
 * Matches the AlbumType enum values from the backend
 */
export const ALBUM_TYPE_OPTIONS = [
  { value: 'studio', label: 'Studio Album' },
  { value: 'live', label: 'Live' },
  { value: 'compilation', label: 'Compilation' },
  { value: 'soundtrack', label: 'Soundtrack' },
  { value: 'remix', label: 'Remix' },
  { value: 'ep', label: 'EP' },
  { value: 'single', label: 'Single' },
  { value: 'demo', label: 'Demo' },
  { value: 'mixtape', label: 'Mixtape' },
  { value: 'bootleg', label: 'Bootleg' },
  { value: 'interview', label: 'Interview' },
  { value: 'audiobook', label: 'Audiobook' },
  { value: 'spoken_word', label: 'Spoken Word' },
  { value: 'other', label: 'Other' },
] as const;

export type AlbumTypeOption = typeof ALBUM_TYPE_OPTIONS[number];
