/**
 * Queue Manager Service
 * Facade for all queue operations with business logic and settings integration
 * This is the ONLY interface UI components should use for queue management
 */

import { createLogger } from '@/app/services/logger';
import { useMusicPlayerStore } from '../../store';
import { useSettingsStore } from '@/app/store/settings';
import {
  MediaType,
  QueueItem,
  QueueState,
  QueueOperationResult,
  QueueError,
  getMediaType,
  isMusicItem,
  songResourceToMusicQueueItem,
  MultiQueueState,
} from './queue-types';
import { queueStorageService } from './queue-storage-service';
import { SongResource } from '@/app/libs/api-client/gen/models';
import { QueueMode, QueueCompletionBehavior } from '@/app/store/settings/settings-types';

const logger = createLogger('QueueManagerService');

// ============================================================================
// QUEUE MANAGER SERVICE INTERFACE
// ============================================================================

export interface IQueueManagerService {
  // Queue switching
  switchQueue(type: MediaType): QueueOperationResult<QueueState>;

  // Queue manipulation
  setQueue(items: QueueItem[], startIndex?: number): QueueOperationResult<void>;
  setQueueAndPlay(items: QueueItem[], publicId: string): QueueOperationResult<void>;
  addToQueue(item: QueueItem): QueueOperationResult<void>;
  insertInQueue(item: QueueItem): QueueOperationResult<void>;
  removeFromQueue(index: number): QueueOperationResult<void>;
  clearQueue(): QueueOperationResult<void>;

  // Playback navigation
  playNext(): QueueOperationResult<QueueItem>;
  playPrevious(): QueueOperationResult<QueueItem>;
  playAtIndex(index: number): QueueOperationResult<QueueItem>;

  // Queue state accessors
  getCurrentQueue(): QueueState;
  getCurrentItem(): QueueItem | null;
  getQueueType(): MediaType;
  getAllQueues(): MultiQueueState;

  // Validation
  canAddToQueue(item: QueueItem): boolean;
  shouldWarnBeforeReplace(item: QueueItem): boolean;
  canMixQueues(): boolean;
}

// ============================================================================
// QUEUE MANAGER SERVICE IMPLEMENTATION
// ============================================================================

class QueueManagerService implements IQueueManagerService {
  private playerStore = useMusicPlayerStore;
  private settingsStore = useSettingsStore;

  // ==========================================================================
  // QUEUE SWITCHING
  // ==========================================================================

  switchQueue(type: MediaType): QueueOperationResult<QueueState> {
    try {
      const state = this.playerStore.getState();
      const currentType = state.activeQueueType;

      if (currentType === type) {
        return { success: true, data: state.queues[type] };
      }

      logger.debug(`Switching queue from ${currentType} to ${type}`);

      // Update store
      this.playerStore.getState().setActiveQueueType(type);

      // Get the target queue
      const targetQueue = state.queues[type];

      // Update current playback state if queue has items
      if (targetQueue.items.length > 0 && targetQueue.currentIndex >= 0) {
        const item = targetQueue.items[targetQueue.currentIndex];
        this.playerStore.getState().setSong({
          publicId: item.publicId,
          title: item.title,
        });
      }

      // Persist to storage
      this.saveToStorage();

      return { success: true, data: targetQueue };
    } catch (error) {
      logger.error('Failed to switch queue:', error);
      return { success: false, error: QueueError.STORAGE_ERROR };
    }
  }

  // ==========================================================================
  // QUEUE MANIPULATION
  // ==========================================================================

  setQueue(items: QueueItem[], startIndex = 0): QueueOperationResult<void> {
    try {
      if (items.length === 0) {
        return { success: false, error: QueueError.QUEUE_EMPTY };
      }

      const firstItem = items[0];
      const mediaType = getMediaType(firstItem);
      const settings = this.settingsStore.getState();
      const state = this.playerStore.getState();

      // Validate all items are same type in simple mode
      if (settings.preferences.queue.mode === QueueMode.SIMPLE) {
        const allSameType = items.every(item => getMediaType(item) === mediaType);
        if (!allSameType) {
          return { success: false, error: QueueError.MODE_VIOLATION };
        }
      }

      // Check if we should warn before replacing
      const currentQueue = state.queues[state.activeQueueType];
      if (settings.preferences.queue.warnOnQueueReplace && currentQueue.items.length > 0) {
        // Different queue type with items - warn user
        if (mediaType !== state.activeQueueType) {
          return { success: false, error: QueueError.MODE_VIOLATION };
        }
      }

      logger.debug(`Setting queue for ${mediaType} with ${items.length} items`);

      // Update the specific queue
      this.playerStore.getState().setQueue(items);
      this.playerStore.getState().setActiveQueueType(mediaType);

      // Start playback at specified index
      this.playerStore.getState().playSongAtIndex(startIndex);

      // Persist
      this.saveToStorage();

      return { success: true, data: undefined };
    } catch (error) {
      logger.error('Failed to set queue:', error);
      return { success: false, error: QueueError.STORAGE_ERROR };
    }
  }

  setQueueAndPlay(items: QueueItem[], publicId: string): QueueOperationResult<void> {
    try {
      if (items.length === 0) {
        return { success: false, error: QueueError.QUEUE_EMPTY };
      }

      const index = items.findIndex(item => item.publicId === publicId);
      if (index === -1) {
        return { success: false, error: QueueError.INVALID_INDEX };
      }

      const mediaType = getMediaType(items[0]);
      const settings = this.settingsStore.getState();

      // Check mode compatibility
      if (settings.preferences.queue.mode === QueueMode.SIMPLE) {
        const allSameType = items.every(item => getMediaType(item) === mediaType);
        if (!allSameType) {
          return { success: false, error: QueueError.MODE_VIOLATION };
        }
      }

      logger.debug(`Setting queue and playing: ${publicId}`);

      // Update queue and play
      this.playerStore.getState().setQueueAndPlay(items, publicId);
      this.playerStore.getState().setActiveQueueType(mediaType);

      // Persist
      this.saveToStorage();

      return { success: true, data: undefined };
    } catch (error) {
      logger.error('Failed to set queue and play:', error);
      return { success: false, error: QueueError.STORAGE_ERROR };
    }
  }

  addToQueue(item: QueueItem): QueueOperationResult<void> {
    try {
      const settings = this.settingsStore.getState();
      const state = this.playerStore.getState();
      const itemType = getMediaType(item);
      const currentType = state.activeQueueType;

      // Check mode compatibility
      if (!this.canAddToQueue(item)) {
        return { success: false, error: QueueError.MODE_VIOLATION };
      }

      logger.debug(`Adding item to ${currentType} queue`);

      // Add to current queue
      this.playerStore.getState().addToQueue(item);

      // Persist
      this.saveToStorage();

      return { success: true, data: undefined };
    } catch (error) {
      logger.error('Failed to add to queue:', error);
      return { success: false, error: QueueError.STORAGE_ERROR };
    }
  }

  insertInQueue(item: QueueItem): QueueOperationResult<void> {
    try {
      const settings = this.settingsStore.getState();
      const state = this.playerStore.getState();
      const itemType = getMediaType(item);
      const currentType = state.activeQueueType;

      // Check mode compatibility
      if (!this.canAddToQueue(item)) {
        return { success: false, error: QueueError.MODE_VIOLATION };
      }

      logger.debug(`Inserting item into ${currentType} queue`);

      // Insert after current track
      this.playerStore.getState().insertInQueue(item);

      // Persist
      this.saveToStorage();

      return { success: true, data: undefined };
    } catch (error) {
      logger.error('Failed to insert in queue:', error);
      return { success: false, error: QueueError.STORAGE_ERROR };
    }
  }

  removeFromQueue(index: number): QueueOperationResult<void> {
    try {
      const state = this.playerStore.getState();

      if (index < 0 || index >= state.queues[state.activeQueueType].items.length) {
        return { success: false, error: QueueError.INVALID_INDEX };
      }

      logger.debug(`Removing item at index ${index}`);

      this.playerStore.getState().removeFromQueue(index);

      // Persist
      this.saveToStorage();

      return { success: true, data: undefined };
    } catch (error) {
      logger.error('Failed to remove from queue:', error);
      return { success: false, error: QueueError.STORAGE_ERROR };
    }
  }

  clearQueue(): QueueOperationResult<void> {
    try {
      logger.debug('Clearing current queue');

      this.playerStore.getState().clearQueue();

      // Persist
      this.saveToStorage();

      return { success: true, data: undefined };
    } catch (error) {
      logger.error('Failed to clear queue:', error);
      return { success: false, error: QueueError.STORAGE_ERROR };
    }
  }

  // ==========================================================================
  // PLAYBACK NAVIGATION
  // ==========================================================================

  playNext(): QueueOperationResult<QueueItem> {
    try {
      const state = this.playerStore.getState();
      const currentQueue = state.queues[state.activeQueueType];

      if (currentQueue.items.length === 0) {
        return { success: false, error: QueueError.QUEUE_EMPTY };
      }

      let nextIndex = currentQueue.currentIndex + 1;
      if (nextIndex >= currentQueue.items.length) {
        // End of queue - check completion behavior
        return this.handleQueueCompletion();
      }

      this.playerStore.getState().playNext();

      const item = currentQueue.items[nextIndex];
      return { success: true, data: item };
    } catch (error) {
      logger.error('Failed to play next:', error);
      return { success: false, error: QueueError.STORAGE_ERROR };
    }
  }

  playPrevious(): QueueOperationResult<QueueItem> {
    try {
      const state = this.playerStore.getState();
      const currentQueue = state.queues[state.activeQueueType];

      if (currentQueue.items.length === 0) {
        return { success: false, error: QueueError.QUEUE_EMPTY };
      }

      this.playerStore.getState().playPrevious();

      const prevIndex = currentQueue.currentIndex - 1;
      const item = currentQueue.items[prevIndex < 0 ? currentQueue.items.length - 1 : prevIndex];

      return { success: true, data: item };
    } catch (error) {
      logger.error('Failed to play previous:', error);
      return { success: false, error: QueueError.STORAGE_ERROR };
    }
  }

  playAtIndex(index: number): QueueOperationResult<QueueItem> {
    try {
      const state = this.playerStore.getState();
      const currentQueue = state.queues[state.activeQueueType];

      if (index < 0 || index >= currentQueue.items.length) {
        return { success: false, error: QueueError.INVALID_INDEX };
      }

      this.playerStore.getState().playSongAtIndex(index);

      const item = currentQueue.items[index];
      return { success: true, data: item };
    } catch (error) {
      logger.error('Failed to play at index:', error);
      return { success: false, error: QueueError.STORAGE_ERROR };
    }
  }

  // ==========================================================================
  // QUEUE STATE ACCESSORS
  // ==========================================================================

  getCurrentQueue(): QueueState {
    const state = this.playerStore.getState();
    return state.queues[state.activeQueueType];
  }

  getCurrentItem(): QueueItem | null {
    const queue = this.getCurrentQueue();
    if (queue.currentIndex < 0 || queue.currentIndex >= queue.items.length) {
      return null;
    }
    return queue.items[queue.currentIndex];
  }

  getQueueType(): MediaType {
    return this.playerStore.getState().activeQueueType;
  }

  getAllQueues(): MultiQueueState {
    const state = this.playerStore.getState();
    return {
      activeQueueType: state.activeQueueType,
      queues: state.queues,
    };
  }

  // ==========================================================================
  // VALIDATION
  // ==========================================================================

  canAddToQueue(item: QueueItem): boolean {
    const settings = this.settingsStore.getState();
    const state = this.playerStore.getState();

    const itemType = getMediaType(item);
    const currentType = state.activeQueueType;
    const currentQueue = state.queues[currentType];

    // Simple mode: strict type checking (unless queue is empty)
    if (settings.preferences.queue.mode === QueueMode.SIMPLE) {
      return itemType === currentType || currentQueue.items.length === 0;
    }

    // Advanced mode: allow mixing
    return true;
  }

  shouldWarnBeforeReplace(item: QueueItem): boolean {
    const settings = this.settingsStore.getState();
    const state = this.playerStore.getState();

    if (!settings.preferences.queue.warnOnQueueReplace) {
      return false;
    }

    const itemType = getMediaType(item);
    const currentType = state.activeQueueType;
    const currentQueue = state.queues[currentType];

    const hasItems = currentQueue.items.length > 0;
    const isDifferentType = itemType !== currentType;

    return hasItems && isDifferentType;
  }

  canMixQueues(): boolean {
    const settings = this.settingsStore.getState();
    return settings.preferences.queue.mode === QueueMode.ADVANCED;
  }

  // ==========================================================================
  // PRIVATE METHODS
  // ==========================================================================

  private handleQueueCompletion(): QueueOperationResult<QueueItem> {
    const settings = this.settingsStore.getState();
    const behavior = settings.preferences.playback.queueCompletion;

    logger.debug(`Queue completed, behavior: ${behavior}`);

    switch (behavior) {
      case QueueCompletionBehavior.STOP:
        this.playerStore.getState().pause();
        return { success: false, error: QueueError.QUEUE_EMPTY };

      case QueueCompletionBehavior.SHUFFLE:
        const currentQueue = this.getCurrentQueue();
        const shuffled = [...currentQueue.items];
        // Fisher-Yates shuffle
        for (let i = shuffled.length - 1; i > 0; i--) {
          const j = Math.floor(Math.random() * (i + 1));
          [shuffled[i], shuffled[j]] = [shuffled[j], shuffled[i]];
        }
        this.playerStore.getState().setQueueAndPlay(shuffled, shuffled[0].publicId);
        return { success: true, data: shuffled[0] };

      case QueueCompletionBehavior.PLAY_RANDOM:
        // TODO: Implement random song from library
        // For now, just stop
        this.playerStore.getState().pause();
        return { success: false, error: QueueError.QUEUE_EMPTY };

      default:
        return { success: false, error: QueueError.QUEUE_EMPTY };
    }
  }

  private saveToStorage(): void {
    const state = this.playerStore.getState();
    const multiQueueState: MultiQueueState = {
      activeQueueType: state.activeQueueType,
      queues: state.queues,
    };
    queueStorageService.save(multiQueueState);
  }
}

// ===========================================================================
// CONVENIENCE FUNCTIONS FOR SONG RESOURCES
// ===========================================================================

/**
 * Set queue from SongResource array (converts to MusicQueueItem)
 */
export function setQueueFromSongs(songs: SongResource[], startIndex = 0): QueueOperationResult<void> {
  const items = songs.map(songResourceToMusicQueueItem);
  return queueManagerService.setQueue(items, startIndex);
}

/**
 * Set queue and play from SongResource array
 */
export function setQueueAndPlayFromSongs(songs: SongResource[], publicId: string): QueueOperationResult<void> {
  const items = songs.map(songResourceToMusicQueueItem);
  return queueManagerService.setQueueAndPlay(items, publicId);
}

// ===========================================================================
// SINGLETON INSTANCE
// ===========================================================================

export const queueManagerService = new QueueManagerService();
