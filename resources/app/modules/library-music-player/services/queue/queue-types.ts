/**
 * Queue type definitions and interfaces
 * Provides type-safe polymorphism for different media types in queues
 */

import { SongResource } from '@/app/libs/api-client/gen/models';
import { MediaType } from '@/app/models/media-type';
import { PlaybackSource } from '@/app/models/playback-source';

// ============================================================================
// BASE QUEUE ITEM
// ============================================================================

/**
 * Base interface for all queue items
 * All media-specific queue items extend this
 */
export interface BaseQueueItem {
  publicId: string;
  title: string;
  duration: number; // seconds
  librarySlug: string;
  mediaType: MediaType;
}

// ============================================================================
// MEDIA-SPECIFIC QUEUE ITEMS
// ============================================================================

/**
 * Music queue item
 */
export interface MusicQueueItem extends BaseQueueItem {
  mediaType: MediaType.MUSIC;
  album?: string;
  artist?: string;
  trackNumber?: number;
}

/**
 * Audiobook queue item
 */
export interface AudiobookQueueItem extends BaseQueueItem {
  mediaType: MediaType.AUDIOBOOK;
  bookTitle: string;
  author: string;
  chapterNumber?: number;
  progress: number; // 0-1, persists position
}

/**
 * Podcast queue item
 */
export interface PodcastQueueItem extends BaseQueueItem {
  mediaType: MediaType.PODCAST;
  podcastTitle: string;
  episodeNumber: number;
  publicationDate: string;
  progress: number; // 0-1, persists position
}

/**
 * Union type for all queue items
 * Use discriminated union with mediaType for type narrowing
 */
export type QueueItem = MusicQueueItem | AudiobookQueueItem | PodcastQueueItem;

// ============================================================================
// TYPE GUARDS
// ============================================================================

/**
 * Get media type from queue item
 */
export function getMediaType(item: QueueItem): MediaType {
  return item.mediaType;
}

/**
 * Check if item is music
 */
export function isMusicItem(item: QueueItem): item is MusicQueueItem {
  return item.mediaType === MediaType.MUSIC;
}

/**
 * Check if item is audiobook
 */
export function isAudiobookItem(item: QueueItem): item is AudiobookQueueItem {
  return item.mediaType === MediaType.AUDIOBOOK;
}

/**
 * Check if item is podcast
 */
export function isPodcastItem(item: QueueItem): item is PodcastQueueItem {
  return item.mediaType === MediaType.PODCAST;
}

// ============================================================================
// QUEUE STATE
// ============================================================================

/**
 * Generic queue state - shared across all queue types
 */
export interface QueueState<T extends QueueItem = QueueItem> {
  items: T[];
  currentIndex: number;
  currentItemPublicId: string | null;
  source: PlaybackSource;
  lastUpdated: number; // timestamp
}

// ============================================================================
// MULTI-QUEUE STATE
// ============================================================================

/**
 * Complete multi-queue state
 * Holds all queues and tracks which is currently active
 */
export interface MultiQueueState {
  activeQueueType: MediaType;
  queues: {
    [MediaType.MUSIC]: QueueState<MusicQueueItem>;
    [MediaType.AUDIOBOOK]: QueueState<AudiobookQueueItem>;
    [MediaType.PODCAST]: QueueState<PodcastQueueItem>;
  };
}

// ============================================================================
// QUEUE OPERATION RESULTS
// ============================================================================

/**
 * Result type for queue operations
 */
export type QueueOperationResult<T> =
  | { success: true; data: T }
  | { success: false; error: QueueError };

/**
 * Queue error types
 */
export enum QueueError {
  QUEUE_TYPE_MISMATCH = 'queue_type_mismatch',
  QUEUE_EMPTY = 'queue_empty',
  INVALID_INDEX = 'invalid_index',
  MODE_VIOLATION = 'mode_violation',
  STORAGE_ERROR = 'storage_error',
}

// ============================================================================
// CONVERSION UTILITIES
// ============================================================================

/**
 * Convert SongResource to MusicQueueItem
 * This will be the primary conversion for music playback
 */
export function songResourceToMusicQueueItem(song: SongResource): MusicQueueItem {
  return {
    publicId: song.publicId,
    title: song.title,
    duration: song.length ?? 0,
    librarySlug: song.librarySlug,
    mediaType: MediaType.MUSIC,
    album: song.album?.title,
    artist: song.artists?.[0]?.name,
    trackNumber: song.track ?? 0,
  };
}

/**
 * Check if queue item can be converted from SongResource
 * For now, all SongResources become MusicQueueItems
 */
export function canConvertSongResource(): boolean {
  return true;
}
