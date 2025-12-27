/**
 * Queue Storage Service
 * Handles localStorage persistence for multi-queue state
 * Designed to be swapped with API sync service in future
 */

import { createLogger } from '@/app/services/logger';
import { MediaType } from '@/app/models/media-type';
import {
  MultiQueueState,
  QueueState,
  MusicQueueItem,
  AudiobookQueueItem,
  PodcastQueueItem,
} from './queue-types';

const logger = createLogger('QueueStorageService');

const STORAGE_KEY = 'baander-multi-queue';
const STORAGE_VERSION = 2;

interface StoredQueueState {
  version: number;
  activeQueueType: MediaType;
  queues: {
    music?: QueueState<MusicQueueItem>;
    audiobook?: QueueState<AudiobookQueueItem>;
    podcast?: QueueState<PodcastQueueItem>;
  };
}

export class QueueStorageService {
  private storageKey: string;

  constructor(key = STORAGE_KEY) {
    this.storageKey = key;
  }

  /**
   * Load all queues from localStorage
   */
  load(): MultiQueueState | null {
    try {
      const raw = localStorage.getItem(this.storageKey);
      if (!raw) {
        logger.debug('No saved queue state found');
        return null;
      }

      const stored: StoredQueueState = JSON.parse(raw);

      // Version migration
      if (stored.version !== STORAGE_VERSION) {
        logger.info(`Migrating queue state from version ${stored.version} to ${STORAGE_VERSION}`);
        return this.migrate(stored);
      }

      return this.normalizeStoredState(stored);
    } catch (error) {
      logger.error('Failed to load queue from storage:', error);
      return null;
    }
  }

  /**
   * Save all queues to localStorage
   */
  save(state: MultiQueueState): void {
    try {
      const toStore: StoredQueueState = {
        version: STORAGE_VERSION,
        activeQueueType: state.activeQueueType,
        queues: {
          music: state.queues[MediaType.MUSIC],
          audiobook: state.queues[MediaType.AUDIOBOOK],
          podcast: state.queues[MediaType.PODCAST],
        },
      };

      const json = JSON.stringify(toStore);

      // Check size (warn if > 1MB)
      const sizeKB = json.length / 1024;
      if (sizeKB > 1024) {
        logger.warn(`Queue state size is large: ${sizeKB.toFixed(2)} KB`);
      }

      localStorage.setItem(this.storageKey, json);
      logger.debug('Queue state saved successfully');
    } catch (error) {
      if (error instanceof DOMException && error.name === 'QuotaExceededError') {
        logger.error('localStorage quota exceeded, cannot save queue');
        throw new Error('QUOTA_EXCEEDED');
      }
      logger.error('Failed to save queue to storage:', error);
      throw error;
    }
  }

  /**
   * Clear all queues from localStorage
   */
  clear(): void {
    try {
      localStorage.removeItem(this.storageKey);
      logger.debug('Queue storage cleared');
    } catch (error) {
      logger.error('Failed to clear queue storage:', error);
    }
  }

  /**
   * Migrate old queue format to new multi-queue format
   */
  private migrate(oldState: StoredQueueState): MultiQueueState {
    // Handle migration from version 1 (single queue) to version 2 (multi-queue)
    if (oldState.version === 1) {
      logger.info('Migrating from single queue to multi-queue format');

      // Old format had a single queue, migrate it to music queue
      const oldQueue = oldState.queues.music || oldState.queues.audiobook || oldState.queues.podcast;

      if (oldQueue) {
        return {
          activeQueueType: MediaType.MUSIC,
          queues: {
            [MediaType.MUSIC]: oldQueue as QueueState<MusicQueueItem>,
            [MediaType.AUDIOBOOK]: this.createEmptyQueue<AudiobookQueueItem>(),
            [MediaType.PODCAST]: this.createEmptyQueue<PodcastQueueItem>(),
          },
        };
      }
    }

    // Fallback: normalize current state
    return this.normalizeStoredState(oldState);
  }

  /**
   * Normalize stored state to MultiQueueState format
   * Ensures all queue types exist and are properly typed
   */
  private normalizeStoredState(stored: StoredQueueState): MultiQueueState {
    return {
      activeQueueType: stored.activeQueueType || MediaType.MUSIC,
      queues: {
        [MediaType.MUSIC]: stored.queues.music || this.createEmptyQueue<MusicQueueItem>(),
        [MediaType.AUDIOBOOK]: stored.queues.audiobook || this.createEmptyQueue<AudiobookQueueItem>(),
        [MediaType.PODCAST]: stored.queues.podcast || this.createEmptyQueue<PodcastQueueItem>(),
      },
    };
  }

  /**
   * Create empty queue state
   */
  private createEmptyQueue<T>(): QueueState<T> {
    return {
      items: [],
      currentIndex: -1,
      currentItemPublicId: null,
      source: 'none' as any,
      lastUpdated: Date.now(),
    };
  }

  /**
   * Get storage size info
   */
  getStorageInfo(): { size: number; sizeKB: number } {
    try {
      const raw = localStorage.getItem(this.storageKey);
      const size = raw ? raw.length : 0;
      return {
        size,
        sizeKB: size / 1024,
      };
    } catch {
      return { size: 0, sizeKB: 0 };
    }
  }
}

// Singleton instance
export const queueStorageService = new QueueStorageService();
