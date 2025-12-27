/**
 * Media type discriminator for queue items
 * Matches LibraryType enum but focused on playback queues
 */
export enum MediaType {
  MUSIC = 'music',
  AUDIOBOOK = 'audiobook',
  PODCAST = 'podcast',
}

/**
 * Type guard to check if a value is a valid MediaType
 */
export function isMediaType(value: string): value is MediaType {
  return Object.values(MediaType).includes(value as MediaType);
}
