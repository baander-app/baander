/**
 * useQueueManager Hook
 * Provides easy access to QueueManagerService for React components
 */

import { useCallback } from 'react';
import { queueManagerService, songResourceToMusicQueueItem } from '../services/queue';
import { MediaType, QueueItem, QueueState, QueueOperationResult } from '../services/queue';
import { SongResource } from '@/app/libs/api-client/gen/models';

export function useQueueManager() {
  // ==========================================================================
  // QUEUE SWITCHING
  // ==========================================================================

  const switchQueue = useCallback((type: MediaType) => {
    return queueManagerService.switchQueue(type);
  }, []);

  // ==========================================================================
  // QUEUE MANIPULATION
  // ==========================================================================

  const setQueue = useCallback((items: QueueItem[], startIndex?: number) => {
    return queueManagerService.setQueue(items, startIndex);
  }, []);

  const setQueueAndPlay = useCallback((items: QueueItem[], publicId: string) => {
    return queueManagerService.setQueueAndPlay(items, publicId);
  }, []);

  const addToQueue = useCallback((item: QueueItem) => {
    return queueManagerService.addToQueue(item);
  }, []);

  const insertInQueue = useCallback((item: QueueItem) => {
    return queueManagerService.insertInQueue(item);
  }, []);

  const removeFromQueue = useCallback((index: number) => {
    return queueManagerService.removeFromQueue(index);
  }, []);

  const clearQueue = useCallback(() => {
    return queueManagerService.clearQueue();
  }, []);

  // ==========================================================================
  // CONVENIENCE FUNCTIONS FOR SONG RESOURCES
  // ==========================================================================

  const setQueueFromSongs = useCallback((songs: SongResource[], startIndex?: number) => {
    const items = songs.map(song => songResourceToMusicQueueItem(song));
    return setQueue(items, startIndex);
  }, [setQueue]);

  const setQueueAndPlayFromSongs = useCallback((songs: SongResource[], publicId: string) => {
    const items = songs.map(song => songResourceToMusicQueueItem(song));
    return setQueueAndPlay(items, publicId);
  }, [setQueueAndPlay]);

  // ==========================================================================
  // PLAYBACK NAVIGATION
  // ==========================================================================

  const playNext = useCallback(() => {
    return queueManagerService.playNext();
  }, []);

  const playPrevious = useCallback(() => {
    return queueManagerService.playPrevious();
  }, []);

  const playAtIndex = useCallback((index: number) => {
    return queueManagerService.playAtIndex(index);
  }, []);

  // ==========================================================================
  // QUEUE STATE ACCESSORS
  // ==========================================================================

  const getCurrentQueue = useCallback(() => {
    return queueManagerService.getCurrentQueue();
  }, []);

  const getCurrentItem = useCallback(() => {
    return queueManagerService.getCurrentItem();
  }, []);

  const getQueueType = useCallback(() => {
    return queueManagerService.getQueueType();
  }, []);

  const getAllQueues = useCallback(() => {
    return queueManagerService.getAllQueues();
  }, []);

  // ==========================================================================
  // VALIDATION
  // ==========================================================================

  const canAddToQueue = useCallback((item: QueueItem) => {
    return queueManagerService.canAddToQueue(item);
  }, []);

  const shouldWarnBeforeReplace = useCallback((item: QueueItem) => {
    return queueManagerService.shouldWarnBeforeReplace(item);
  }, []);

  const canMixQueues = useCallback(() => {
    return queueManagerService.canMixQueues();
  }, []);

  return {
    // Queue switching
    switchQueue,

    // Queue manipulation
    setQueue,
    setQueueAndPlay,
    addToQueue,
    insertInQueue,
    removeFromQueue,
    clearQueue,

    // Convenience functions
    setQueueFromSongs,
    setQueueAndPlayFromSongs,

    // Playback navigation
    playNext,
    playPrevious,
    playAtIndex,

    // Queue state accessors
    getCurrentQueue,
    getCurrentItem,
    getQueueType,
    getAllQueues,

    // Validation
    canAddToQueue,
    shouldWarnBeforeReplace,
    canMixQueues,
  };
}
