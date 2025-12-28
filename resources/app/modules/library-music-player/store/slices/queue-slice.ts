/**
 * Multi-Queue Slice
 * Refactored to support multiple queues (music, audiobook, podcast)
 * Maintains backward compatibility with existing selectors
 */

import { StateCreator } from 'zustand';
import {
  MusicQueueItem,
  AudiobookQueueItem,
  PodcastQueueItem,
  QueueItem,
  QueueState,
  QueueOperationResult,
  QueueError,
  getMediaType,
  isMusicItem,
  isAudiobookItem,
  isPodcastItem,
} from '../../services/queue';
import { PlaybackSource } from '@/app/models/playback-source';
import { QueueMode } from '@/app/store/settings/settings-types';
import { useSettingsStore } from '@/app/store/settings';
import { MediaType } from '@/app/models/media-type.ts';

// ============================================================================
// QUEUE SLICE INTERFACE
// ============================================================================

export interface QueueSlice {
  // State
  activeQueueType: MediaType;
  queues: {
    [MediaType.MUSIC]: QueueState<MusicQueueItem>;
    [MediaType.AUDIOBOOK]: QueueState<AudiobookQueueItem>;
    [MediaType.PODCAST]: QueueState<PodcastQueueItem>;
  };

  // Actions - Queue switching
  setActiveQueueType: (type: MediaType) => void;
  switchQueueType: (type: MediaType) => void;

  // Actions - Queue manipulation (works with active queue)
  setQueue: <T extends QueueItem>(queue: T[]) => void;
  addToQueue: <T extends QueueItem>(item: T) => void;
  insertInQueue: <T extends QueueItem>(item: T) => void;
  addManyToQueue: <T extends QueueItem>(items: T[]) => void;
  removeFromQueue: (index: number) => void;
  clearQueue: () => void;

  // Actions - Playback navigation
  playSongAtIndex: (index: number) => void;
  playNext: () => void;
  playPrevious: () => void;

  // Actions - Special queue operations
  setQueueAndPlay: <T extends QueueItem>(queue: T[], publicId: string) => void;
  shuffleAndPlay: <T extends QueueItem>(songs: T[]) => void;
  setPlaybackSource: (source: PlaybackSource) => void;
}

// ============================================================================
// CREATE QUEUE SLICE
// ============================================================================

export const createQueueSlice: StateCreator<QueueSlice> = (set, get) => ({
  // =========================================================================
  // INITIAL STATE
  // =========================================================================
  activeQueueType: MediaType.MUSIC,
  queues: {
    [MediaType.MUSIC]: {
      items: [],
      currentIndex: -1,
      currentItemPublicId: null,
      source: PlaybackSource.NONE,
      lastUpdated: Date.now(),
    },
    [MediaType.AUDIOBOOK]: {
      items: [],
      currentIndex: -1,
      currentItemPublicId: null,
      source: PlaybackSource.NONE,
      lastUpdated: Date.now(),
    },
    [MediaType.PODCAST]: {
      items: [],
      currentIndex: -1,
      currentItemPublicId: null,
      source: PlaybackSource.NONE,
      lastUpdated: Date.now(),
    },
  },

  // =========================================================================
  // QUEUE SWITCHING
  // =========================================================================

  setActiveQueueType: (type) => set({ activeQueueType: type }),

  switchQueueType: (type) => set((state) => {
    if (type === state.activeQueueType) {
      return {}; // No change needed
    }

    return {
      activeQueueType: type,
    };
  }),

  // =========================================================================
  // QUEUE MANIPULATION
  // =========================================================================

  setQueue: <T extends QueueItem>(queue: T[]) => set((state) => {
    const activeType = state.activeQueueType;

    return {
      queues: {
        ...state.queues,
        [activeType]: {
          ...state.queues[activeType],
          items: queue,
          lastUpdated: Date.now(),
        },
      },
      source: PlaybackSource.LIBRARY,
    };
  }),

  addToQueue: <T extends QueueItem>(item: T) => set((state) => {
    const activeType = state.activeQueueType;

    return {
      queues: {
        ...state.queues,
        [activeType]: {
          ...state.queues[activeType],
          items: [...state.queues[activeType].items, item],
          lastUpdated: Date.now(),
        },
      },
      source: PlaybackSource.LIBRARY,
    };
  }),

  insertInQueue: <T extends QueueItem>(item: T) => set((state) => {
    const activeType = state.activeQueueType;
    const currentQueue = state.queues[activeType];
    const insertIndex = currentQueue.currentIndex + 1;

    const newItems = [...currentQueue.items];
    newItems.splice(insertIndex, 0, item);

    return {
      queues: {
        ...state.queues,
        [activeType]: {
          ...currentQueue,
          items: newItems,
          lastUpdated: Date.now(),
        },
      },
      source: PlaybackSource.LIBRARY,
    };
  }),

  addManyToQueue: <T extends QueueItem>(items: T[]) => set((state) => {
    const activeType = state.activeQueueType;

    return {
      queues: {
        ...state.queues,
        [activeType]: {
          ...state.queues[activeType],
          items: [...state.queues[activeType].items, ...items],
          lastUpdated: Date.now(),
        },
      },
      source: PlaybackSource.LIBRARY,
    };
  }),

  removeFromQueue: (index) => set((state) => {
    const activeType = state.activeQueueType;
    const currentQueue = state.queues[activeType];

    const newItems = [...currentQueue.items];
    newItems.splice(index, 1);

    // Adjust current index if needed
    let newIndex = currentQueue.currentIndex;
    if (index < currentQueue.currentIndex) {
      newIndex = currentQueue.currentIndex - 1;
    } else if (index === currentQueue.currentIndex) {
      // If removing current item, stay at same index (next item becomes current)
      newIndex = Math.min(index, newItems.length - 1);
    }

    return {
      queues: {
        ...state.queues,
        [activeType]: {
          ...currentQueue,
          items: newItems,
          currentIndex: newIndex,
          currentItemPublicId: newIndex >= 0 ? newItems[newIndex]?.publicId ?? null : null,
          lastUpdated: Date.now(),
        },
      },
    };
  }),

  clearQueue: () => set((state) => {
    const activeType = state.activeQueueType;

    return {
      queues: {
        ...state.queues,
        [activeType]: {
          items: [],
          currentIndex: -1,
          currentItemPublicId: null,
          source: PlaybackSource.NONE,
          lastUpdated: Date.now(),
        },
      },
    };
  }),

  // =========================================================================
  // PLAYBACK NAVIGATION
  // =========================================================================

  playSongAtIndex: (index) => set((state) => {
    const activeType = state.activeQueueType;
    const currentQueue = state.queues[activeType];

    if (index < 0 || index >= currentQueue.items.length) {
      return {};
    }

    const item = currentQueue.items[index];

    return {
      queues: {
        ...state.queues,
        [activeType]: {
          ...currentQueue,
          currentIndex: index,
          currentItemPublicId: item.publicId,
          lastUpdated: Date.now(),
        },
      },
      song: { publicId: item.publicId, title: item.title },
    };
  }),

  playNext: () => set((state) => {
    const activeType = state.activeQueueType;
    const currentQueue = state.queues[activeType];

    if (currentQueue.items.length === 0) {
      return {};
    }

    let nextIndex = currentQueue.currentIndex + 1;
    if (nextIndex >= currentQueue.items.length) {
      nextIndex = 0; // Loop back to start
    }

    const item = currentQueue.items[nextIndex];

    return {
      queues: {
        ...state.queues,
        [activeType]: {
          ...currentQueue,
          currentIndex: nextIndex,
          currentItemPublicId: item.publicId,
          lastUpdated: Date.now(),
        },
      },
      song: { publicId: item.publicId, title: item.title },
    };
  }),

  playPrevious: () => set((state) => {
    const activeType = state.activeQueueType;
    const currentQueue = state.queues[activeType];

    if (currentQueue.items.length === 0) {
      return {};
    }

    let prevIndex = currentQueue.currentIndex - 1;
    if (prevIndex < 0) {
      prevIndex = currentQueue.items.length - 1; // Loop to end
    }

    const item = currentQueue.items[prevIndex];

    return {
      queues: {
        ...state.queues,
        [activeType]: {
          ...currentQueue,
          currentIndex: prevIndex,
          currentItemPublicId: item.publicId,
          lastUpdated: Date.now(),
        },
      },
      song: { publicId: item.publicId, title: item.title },
    };
  }),

  // =========================================================================
  // SPECIAL QUEUE OPERATIONS
  // =========================================================================

  setQueueAndPlay: <T extends QueueItem>(queue: T[], publicId: string) => set((state) => {
    const activeType = state.activeQueueType;
    const index = queue.findIndex(item => item.publicId === publicId);

    return {
      queues: {
        ...state.queues,
        [activeType]: {
          items: queue,
          currentIndex: index,
          currentItemPublicId: publicId,
          source: PlaybackSource.LIBRARY,
          lastUpdated: Date.now(),
        },
      },
    };
  }),

  shuffleAndPlay: <T extends QueueItem>(songs: T[]) => set(() => {
    // Fisher-Yates shuffle
    const shuffled = [...songs];
    for (let i = shuffled.length - 1; i > 0; i--) {
      const j = Math.floor(Math.random() * (i + 1));
      [shuffled[i], shuffled[j]] = [shuffled[j], shuffled[i]];
    }

    const activeType = MediaType.MUSIC; // Default to music for shuffle

    return {
      queues: {
        [MediaType.MUSIC]: {
          items: shuffled as any,
          currentIndex: 0,
          currentItemPublicId: shuffled.length > 0 ? shuffled[0].publicId : null,
          source: PlaybackSource.LIBRARY,
          lastUpdated: Date.now(),
        },
        [MediaType.AUDIOBOOK]: {
          items: [],
          currentIndex: -1,
          currentItemPublicId: null,
          source: PlaybackSource.NONE,
          lastUpdated: Date.now(),
        },
        [MediaType.PODCAST]: {
          items: [],
          currentIndex: -1,
          currentItemPublicId: null,
          source: PlaybackSource.NONE,
          lastUpdated: Date.now(),
        },
      },
      activeQueueType: activeType,
    };
  }),

  setPlaybackSource: (source) => set((state) => {
    const activeType = state.activeQueueType;

    return {
      queues: {
        ...state.queues,
        [activeType]: {
          ...state.queues[activeType],
          source,
        },
      },
    };
  }),

  // =========================================================================
  // BACKWARD COMPATIBILITY - GETTERS
  // =========================================================================

  // Note: Getters removed as they cause initialization issues.
  // Use selectors from utilities.ts instead:
  // - usePlayerQueue() -> state.queues[state.activeQueueType].items
  // - usePlayerCurrentSongIndex() -> state.queues[state.activeQueueType].currentIndex
  // - usePlayerCurrentSongPublicId() -> state.queues[state.activeQueueType].currentItemPublicId
});
