import { useEffect, useRef, useState } from 'react';
import { createLogger } from '../../../services/logger';
import { globalAudioProcessor } from '../../../services/global-audio-processor-service';
import { useMusicPlayerStore } from './music-player-store';

const logger = createLogger('MusicPlayerUtilities');

// Configuration for time update throttling
export const TIME_UPDATE_THROTTLE_MS = 250; // Update at most 4 times per second

// Types for consumers
export type Song = { publicId: string; title?: string; } | null;

export type ProcessorApi = {
  connect?: (el: HTMLAudioElement) => Promise<void> | void;
  setPlayingState?: (playing: boolean) => void;
  resumeContextIfNeeded?: () => Promise<void> | void;
} | null;

export type PlayerEventHandlers = {
  onLoadStart?: () => void;
  onCanPlay?: () => void;
  onCanPlayThrough?: (loadDurationMs: number) => void;
  onPlay?: () => void;
  onPause?: () => void;
  onEnded?: () => void;
  onError?: (error: MediaError | null) => void;
  onBuffer?: (percentage: number) => void;
};

/**
 * Attach an HTMLAudioElement to the store and wire events.
 * Optional handlers let the app record metrics/telemetry without coupling the store.
 * Returns a cleanup function to detach listeners.
 */
export function attachAudioElement(el: HTMLAudioElement, handlers?: PlayerEventHandlers) {
  const st = useMusicPlayerStore.getState();
  st.setAudioEl(el);

  // Initialize element from store
  el.muted = st.isMuted;
  el.volume = st.isMuted ? 0 : st.volumePercent / 100;

  // If a source is already set in state, apply it
  if (st.src) {
    el.src = st.src;
    el.preload = 'auto';
  }

  // Attempt to connect processor if available and element has a source
  if (st.processor && !st.processorConnected && el.src) {
    logger.debug('Connecting audio element to processor...');
    Promise.resolve(st.processor.connect?.(el))
      .then(() => {
        logger.debug('Processor connected successfully');
        useMusicPlayerStore.setState({ processorConnected: true });
      })
      .catch((err) => {
        logger.error('Failed to connect processor:', err);
        useMusicPlayerStore.setState({ processorConnected: false });
      });
  } else {
    logger.debug('Skipping processor connection:', { hasProcessor: !!st.processor, isConnected: st.processorConnected, hasSource: !!el.src });
  }

  // Throttle currentTime updates
  let rafId: number | null = null;
  let pendingTime: number | null = null;

  const onTimeUpdate = () => {
    pendingTime = el.currentTime;

    if (rafId == null) {
      rafId = requestAnimationFrame(() => {
        rafId = null;
        if (pendingTime != null) {
          const state = useMusicPlayerStore.getState();
          const timeSinceLastUpdate = performance.now() - state.lastTimeUpdateMs;

          if (timeSinceLastUpdate >= TIME_UPDATE_THROTTLE_MS ||
            pendingTime < 0.1 ||
            pendingTime >= (state.duration - 0.1) ||
            Math.abs(pendingTime - state.currentTime) > 0.5) {

            state.setCurrentTime(pendingTime);
          }
          pendingTime = null;
        }
      });
    }
  };

  let loadStart = 0;
  const onLoadStart = () => {
    loadStart = performance.now();
    handlers?.onLoadStart?.();
  };
  const onDurationChange = () => {
    useMusicPlayerStore.getState().setDuration(Number.isFinite(el.duration) ? el.duration : 0);
  };
  const onCanPlay = () => {
    useMusicPlayerStore.getState().setIsReady(true);
    handlers?.onCanPlay?.();
  };
  const onCanPlayThrough = () => {
    const dur = performance.now() - loadStart;
    handlers?.onCanPlayThrough?.(dur);
  };
  const onProgress = () => {
    try {
      const dur = el.duration;
      if (!Number.isFinite(dur) || dur <= 0 || el.buffered.length === 0) return;
      for (let i = el.buffered.length - 1; i >= 0; i--) {
        if (el.buffered.start(i) <= el.currentTime) {
          const end = el.buffered.end(i);
          useMusicPlayerStore.getState().setBuffered(end);
          const pct = Math.max(0, Math.min(100, (end / dur) * 100));
          handlers?.onBuffer?.(pct);
          break;
        }
      }
    } catch {
      // ignore
    }
  };

  const onPlay = () => {
    useMusicPlayerStore.getState().setIsPlaying(true);
    handlers?.onPlay?.();
  };
  const onPause = () => {
    useMusicPlayerStore.getState().setIsPlaying(false);
    handlers?.onPause?.();
  };
  const onEnded = () => {
    useMusicPlayerStore.getState().setIsPlaying(false);
    handlers?.onEnded?.();
  };
  const onError = () => handlers?.onError?.(el.error || null);

  const onSeeking = () => {
    useMusicPlayerStore.getState().setCurrentTime(el.currentTime);
  };

  el.addEventListener('loadstart', onLoadStart);
  el.addEventListener('timeupdate', onTimeUpdate);
  el.addEventListener('durationchange', onDurationChange);
  el.addEventListener('canplay', onCanPlay);
  el.addEventListener('canplaythrough', onCanPlayThrough);
  el.addEventListener('progress', onProgress);
  el.addEventListener('play', onPlay);
  el.addEventListener('pause', onPause);
  el.addEventListener('ended', onEnded);
  el.addEventListener('error', onError);
  el.addEventListener('seeking', onSeeking);

  return () => {
    if (rafId != null) cancelAnimationFrame(rafId);
    el.removeEventListener('loadstart', onLoadStart);
    el.removeEventListener('timeupdate', onTimeUpdate);
    el.removeEventListener('durationchange', onDurationChange);
    el.removeEventListener('canplay', onCanPlay);
    el.removeEventListener('canplaythrough', onCanPlayThrough);
    el.removeEventListener('progress', onProgress);
    el.removeEventListener('play', onPlay);
    el.removeEventListener('pause', onPause);
    el.removeEventListener('ended', onEnded);
    el.removeEventListener('error', onError);
    el.removeEventListener('seeking', onSeeking);
    useMusicPlayerStore.getState().setAudioEl(null);
  };
}

/**
 * Convenience: autoplay if user has interacted and a source exists.
 */
export async function autoplayIfAllowed() {
  const st = useMusicPlayerStore.getState();
  if (!st.audioEl || !st.src) return;
  if (!st.hasUserInteracted) return;
  await st.play();
}

/**
 * Initialize and connect the global audio processor to the music player store
 */
export async function initializeGlobalAudioProcessor() {
  logger.debug('initializeGlobalAudioProcessor: Starting...');
  const store = useMusicPlayerStore.getState();

  // Initialize the global processor
  logger.debug('initializeGlobalAudioProcessor: Calling globalAudioProcessor.initialize()...');
  globalAudioProcessor.initialize();

  // Create the processor API that matches the expected interface
  const processorApi: ProcessorApi = {
    connect: async (el: HTMLAudioElement) => {
      await globalAudioProcessor.connectAudioElement(el);
    },
    setPlayingState: (playing: boolean) => {
      globalAudioProcessor.setPlayingState(playing);
    },
    resumeContextIfNeeded: async () => {
      await globalAudioProcessor.resumeContextIfNeeded();
    }
  };

  // Connect the processor API to the store
  logger.debug('initializeGlobalAudioProcessor: Connecting processor to store...');
  await store.connectAudioProcessor(processorApi);

  logger.debug('initializeGlobalAudioProcessor: Completed!');
  return processorApi;
}

/**
 * Reset the global audio processor connection
 */
export function resetGlobalAudioProcessor() {
  globalAudioProcessor.reset();
  const store = useMusicPlayerStore.getState();
  store.connectAudioProcessor(null);
}

// Selector hooks
export const usePlayerDuration = () => useMusicPlayerStore(s => s.duration);

export function usePlayerCurrentTime() {
  const currentTime = useMusicPlayerStore(s => s.currentTime);
  const isPlaying = useMusicPlayerStore(s => s.isPlaying);

  const [throttledTime, setThrottledTime] = useState(currentTime);
  const lastUpdateRef = useRef(performance.now());
  const currentTimeRef = useRef(currentTime);

  currentTimeRef.current = currentTime;

  useEffect(() => {
    if (!isPlaying) {
      setThrottledTime(currentTime);
      return;
    }

    const updateInterval = setInterval(() => {
      const now = performance.now();
      if (now - lastUpdateRef.current >= TIME_UPDATE_THROTTLE_MS) {
        setThrottledTime(currentTimeRef.current);
        lastUpdateRef.current = now;
      }
    }, TIME_UPDATE_THROTTLE_MS);

    return () => clearInterval(updateInterval);
  }, [isPlaying, currentTime]);

  useEffect(() => {
    const diff = Math.abs(throttledTime - currentTime);
    if (diff > 0.5) {
      setThrottledTime(currentTime);
      lastUpdateRef.current = performance.now();
    }
  }, [currentTime, throttledTime]);

  return throttledTime;
}

export const usePlayerBuffered = () => useMusicPlayerStore(s => s.buffered);
export const usePlayerIsPlaying = () => useMusicPlayerStore(s => s.isPlaying);
export const usePlayerIsReady = () => useMusicPlayerStore(s => s.isReady);
export const usePlayerVolumePercent = () => useMusicPlayerStore(s => s.volumePercent);
export const usePlayerIsMuted = () => useMusicPlayerStore(s => s.isMuted);
export const usePlayerSong = () => useMusicPlayerStore(s => s.song);
export const usePlayerAudioElement = () => useMusicPlayerStore(s => s.audioEl);
export const usePlayerHasUserInteracted = () => useMusicPlayerStore(s => s.hasUserInteracted);

// Queue selectors
export const usePlayerQueue = () => useMusicPlayerStore(s => s.queues[s.activeQueueType].items);
export const usePlayerCurrentSongIndex = () => useMusicPlayerStore(s => s.queues[s.activeQueueType].currentIndex);
export const usePlayerCurrentSongPublicId = () => useMusicPlayerStore(s => s.queues[s.activeQueueType].currentItemPublicId);

export const usePlayerCurrentSong = () =>
  useMusicPlayerStore(s => {
    const activeQueue = s.queues[s.activeQueueType];
    if (activeQueue.items.length === 0 || activeQueue.currentIndex < 0) return null;
    return activeQueue.items[activeQueue.currentIndex] || null;
  });

// Playback mode selectors
export const usePlayerShuffleEnabled = () => useMusicPlayerStore(s => s.playbackMode.isShuffleEnabled);
export const usePlayerRepeatEnabled = () => useMusicPlayerStore(s => s.playbackMode.isRepeatEnabled);

// Progress & source selectors
export const usePlayerProgress = () => useMusicPlayerStore(s => s.progress);
export const usePlayerSource = () => useMusicPlayerStore(s => s.streamUrl);

// Lyrics selector
export const usePlayerLyricsOffset = () => useMusicPlayerStore(s => s.lyricsOffset);

// Analysis data selectors
export const usePlayerAnalysis = () => useMusicPlayerStore(s => s.analysis);
export const usePlayerLufs = () => useMusicPlayerStore(s => s.analysis.lufs);

// Action accessors
export function usePlayerActions() {
  return {
    seekTo: useMusicPlayerStore(s => s.seekTo),
    play: useMusicPlayerStore(s => s.play),
    pause: useMusicPlayerStore(s => s.pause),
    togglePlayPause: useMusicPlayerStore(s => s.togglePlayPause),
    setVolumePercent: useMusicPlayerStore(s => s.setVolumePercent),
    setMuted: useMusicPlayerStore(s => s.setMuted),
    toggleMute: useMusicPlayerStore(s => s.toggleMute),
    setSong: useMusicPlayerStore(s => s.setSong),
    setSource: useMusicPlayerStore(s => s.setSource),
    setHasUserInteracted: useMusicPlayerStore(s => s.setHasUserInteracted),
    connectAudioProcessor: useMusicPlayerStore(s => s.connectAudioProcessor),
    resumeProcessorContext: useMusicPlayerStore(s => s.resumeProcessorContext),

    // Queue management
    setQueue: useMusicPlayerStore(s => s.setQueue),
    addToQueue: useMusicPlayerStore(s => s.addToQueue),
    insertInQueue: useMusicPlayerStore(s => s.insertInQueue),
    addManyToQueue: useMusicPlayerStore(s => s.addManyToQueue),
    removeFromQueue: useMusicPlayerStore(s => s.removeFromQueue),
    playSongAtIndex: useMusicPlayerStore(s => s.playSongAtIndex),
    playNext: useMusicPlayerStore(s => s.playNext),
    playPrevious: useMusicPlayerStore(s => s.playPrevious),
    setQueueAndPlay: useMusicPlayerStore(s => s.setQueueAndPlay),
    shuffleAndPlay: useMusicPlayerStore(s => s.shuffleAndPlay),

    // Playback mode
    setShuffleEnabled: useMusicPlayerStore(s => s.setShuffleEnabled),
    setRepeatEnabled: useMusicPlayerStore(s => s.setRepeatEnabled),

    // Progress & source
    setProgress: useMusicPlayerStore(s => s.setProgress),
    setPlaybackSource: useMusicPlayerStore(s => s.setPlaybackSource),

    // Lyrics
    setLyricsOffset: useMusicPlayerStore(s => s.setLyricsOffset),

    // Analysis
    setLeftChannel: useMusicPlayerStore(s => s.setLeftChannel),
    setRightChannel: useMusicPlayerStore(s => s.setRightChannel),
    setFrequencies: useMusicPlayerStore(s => s.setFrequencies),
    setLufs: useMusicPlayerStore(s => s.setLufs),
    setBufferSize: useMusicPlayerStore(s => s.setBufferSize),

    // Global processor functions
    initializeGlobalAudioProcessor,
    resetGlobalAudioProcessor,
  };
}
