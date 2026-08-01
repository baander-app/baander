/**
 * Field definitions for the metadata editor.
 *
 * Organized into sections following Apple HIG grouped-table pattern.
 * Each entity type defines its own sections and fields.
 */

export type FieldType = 'text' | 'number' | 'textarea' | 'select' | 'toggle'

export interface FieldDef {
  key: string
  label: string
  type: FieldType
  /** For select fields, the available options */
  options?: { value: string; label: string }[]
  /** If true, field is read-only (technical info, external IDs) */
  readOnly?: boolean
  /** Placeholder text when value is empty */
  placeholder?: string
  /** Whether this field can be locked */
  lockable?: boolean
}

export interface SectionDef {
  label: string
  fields: FieldDef[]
}

export interface EntityFieldConfig {
  sections: SectionDef[]
}

// ── Song ──────────────────────────────────────────────────────────────────────

export const SONG_FIELDS: EntityFieldConfig = {
  sections: [
    {
      label: 'Song',
      fields: [
        { key: 'title', label: 'Title', type: 'text', placeholder: 'Song title', lockable: true },
        { key: 'track', label: 'Track', type: 'number', placeholder: '#' },
        { key: 'disc', label: 'Disc', type: 'number', placeholder: '#' },
        { key: 'year', label: 'Year', type: 'number', placeholder: 'Year' },
      ],
    },
    {
      label: 'Details',
      fields: [
        { key: 'comment', label: 'Comment', type: 'text', placeholder: 'Comment' },
        { key: 'lyrics', label: 'Lyrics', type: 'textarea', placeholder: 'Lyrics' },
        { key: 'explicit', label: 'Explicit', type: 'toggle' },
      ],
    },
    {
      label: 'File',
      fields: [
        { key: 'path', label: 'Path', type: 'text', readOnly: true },
        { key: 'bitrate', label: 'Bitrate', type: 'text', readOnly: true },
        { key: 'sampleRate', label: 'Sample Rate', type: 'text', readOnly: true },
        { key: 'channels', label: 'Channels', type: 'text', readOnly: true },
        { key: 'codec', label: 'Codec', type: 'text', readOnly: true },
        { key: 'mimeType', label: 'MIME Type', type: 'text', readOnly: true },
      ],
    },
    {
      label: 'External IDs',
      fields: [
        { key: 'mbid', label: 'MusicBrainz', type: 'text', readOnly: true },
        { key: 'discogsId', label: 'Discogs', type: 'text', readOnly: true },
        { key: 'spotifyId', label: 'Spotify', type: 'text', readOnly: true },
      ],
    },
  ],
}

// ── Album ─────────────────────────────────────────────────────────────────────

export const ALBUM_FIELDS: EntityFieldConfig = {
  sections: [
    {
      label: 'Album',
      fields: [
        { key: 'title', label: 'Title', type: 'text', placeholder: 'Album title', lockable: true },
        {
          key: 'type',
          label: 'Type',
          type: 'select',
          options: [
            { value: 'album', label: 'Album' },
            { value: 'single', label: 'Single' },
            { value: 'ep', label: 'EP' },
            { value: 'compilation', label: 'Compilation' },
            { value: 'soundtrack', label: 'Soundtrack' },
            { value: 'live', label: 'Live' },
            { value: 'remix', label: 'Remix' },
            { value: 'other', label: 'Other' },
          ],
          lockable: true,
        },
        { key: 'year', label: 'Year', type: 'number', placeholder: 'Year' },
      ],
    },
    {
      label: 'Publisher',
      fields: [
        { key: 'label', label: 'Label', type: 'text', placeholder: 'Record label' },
        { key: 'catalogNumber', label: 'Catalog #', type: 'text', placeholder: 'Catalog number' },
        { key: 'barcode', label: 'Barcode', type: 'text', placeholder: 'Barcode' },
      ],
    },
    {
      label: 'Region',
      fields: [
        { key: 'country', label: 'Country', type: 'text', placeholder: 'Country' },
        { key: 'language', label: 'Language', type: 'text', placeholder: 'Language' },
      ],
    },
    {
      label: 'Notes',
      fields: [
        { key: 'disambiguation', label: 'Disambiguation', type: 'text', placeholder: 'Disambiguation' },
        { key: 'annotation', label: 'Annotation', type: 'textarea', placeholder: 'Notes about this release' },
      ],
    },
    {
      label: 'External IDs',
      fields: [
        { key: 'mbid', label: 'MusicBrainz', type: 'text', readOnly: true },
        { key: 'discogsId', label: 'Discogs', type: 'text', readOnly: true },
        { key: 'spotifyId', label: 'Spotify', type: 'text', readOnly: true },
      ],
    },
  ],
}

// ── Artist ────────────────────────────────────────────────────────────────────

export const ARTIST_FIELDS: EntityFieldConfig = {
  sections: [
    {
      label: 'Artist',
      fields: [
        { key: 'name', label: 'Name', type: 'text', placeholder: 'Artist name', lockable: true },
        {
          key: 'type',
          label: 'Type',
          type: 'select',
          options: [
            { value: 'person', label: 'Person' },
            { value: 'group', label: 'Group' },
            { value: 'other', label: 'Other' },
            { value: 'character', label: 'Character' },
          ],
        },
      ],
    },
    {
      label: 'Details',
      fields: [
        { key: 'country', label: 'Country', type: 'text', placeholder: 'Country' },
        { key: 'gender', label: 'Gender', type: 'text', placeholder: 'Gender' },
        { key: 'sortName', label: 'Sort Name', type: 'text', placeholder: 'Sort as' },
        { key: 'disambiguation', label: 'Disambiguation', type: 'text', placeholder: 'Disambiguation' },
      ],
    },
    {
      label: 'Biography',
      fields: [
        { key: 'biography', label: 'Biography', type: 'textarea', placeholder: 'Artist biography' },
      ],
    },
    {
      label: 'External IDs',
      fields: [
        { key: 'mbid', label: 'MusicBrainz', type: 'text', readOnly: true },
        { key: 'discogsId', label: 'Discogs', type: 'text', readOnly: true },
        { key: 'spotifyId', label: 'Spotify', type: 'text', readOnly: true },
      ],
    },
  ],
}

export function getFieldConfig(entityType: 'song' | 'album' | 'artist'): EntityFieldConfig {
  switch (entityType) {
    case 'song':
      return SONG_FIELDS
    case 'album':
      return ALBUM_FIELDS
    case 'artist':
      return ARTIST_FIELDS
  }
}
