import { create } from 'zustand';
import { subscribeWithSelector } from 'zustand/middleware';
import { globalAudioProcessor } from '../../../services/global-audio-processor-service';
import { useEffect, useRef, useState } from 'react';

type Song = { publicId: string; title?: string; } | null;

type ProcessorApi = {
  connect?: (el: HTMLAudioElement) => Promise<void> | void;
  setPlayingState?: (playing: boolean) => void;
  resumeContextIfNeeded?: () => Promise<void> | void;
} | null;

type PlayerEventHandlers = {
  onLoadStart?: () => void;
  onCanPlay?: () => void;
  onCanPlayThrough?: (loadDurationMs: number) => void;
  onPlay?: () => void;
  onPause?: () => void;
  onEnded?: () => void;
  onError?: (error: MediaError | null) => void;
  onBuffer?: (percentage: number) => void;
};

export type MusicPlayerState = {
  // Core timing (seconds)
  duration: number;
  currentTime: number;
  buffered: number;

  // Playback
  isPlaying: boolean;
  isReady: boolean;

  // Volume
  volumePercent: number; // 0..100
  isMuted: boolean;

  // Metadata / source
  song: Song;
  src: string | null;

  // Element
  audioEl: HTMLAudioElement | null;

  // UX gating
  hasUserInteracted: boolean;

  // Processor integration
  processor: ProcessorApi;
  processorConnected: boolean;

  // Throttling flags
  lastTimeUpdateMs: number;

  // Actions (pure state)
  setDuration: (s: number) => void;
  setCurrentTime: (s: number) => void;
  setBuffered: (s: number) => void;
  setIsPlaying: (v: boolean) => void;
  setIsReady: (v: boolean) => void;

  setVolumePercent: (v: number) => void;
  setMuted: (v: boolean) => void;
  toggleMute: () => void;

  setSong: (song: Song) => void;
  setSource: (src: string | null) => void;

  // UX gating
  setHasUserInteracted: (v: boolean) => void;

  // Control (uses audio element if present)
  setAudioEl: (el: HTMLAudioElement | null) => void;
  seekTo: (s: number) => void;
  play: () => Promise<void> | void;
  pause: () => void;
  togglePlayPause: () => Promise<void> | void;

  // Processor
  connectAudioProcessor: (api: ProcessorApi) => Promise<void> | void;
  resumeProcessorContext: () => Promise<void> | void;
};

// Configuration for time update throttling
const TIME_UPDATE_THROTTLE_MS = 250; // Update at most 4 times per second

export const useMusicPlayerStore = create<MusicPlayerState>()(
  subscribeWithSelector((set, get) => ({
    duration: 0,
    currentTime: 0,
    buffered: 0,

    isPlaying: false,
    isReady: false,

    volumePercent: 100,
    isMuted: false,

    song: null,
    src: null,

    audioEl: null,

    hasUserInteracted: false,

    processor: null,
    processorConnected: false,

    // Add throttling timestamp
    lastTimeUpdateMs: 0,

    setDuration: (s) => set({ duration: Number.isFinite(s) ? s : 0 }),
    setCurrentTime: (s) => set({
      currentTime: Number.isFinite(s) ? s : 0,
      lastTimeUpdateMs: performance.now()
    }),
    setBuffered: (s) => set({ buffered: Number.isFinite(s) ? s : 0 }),
    setIsPlaying: (v) => {
      set({ isPlaying: !!v });
      const proc = get().processor;
      proc?.setPlayingState?.(!!v);
    },
    setIsReady: (v) => set({ isReady: !!v }),

    setVolumePercent: (v) => {
      const level = Math.max(0, Math.min(100, Math.round(v)));
      set({ volumePercent: level });
      const el = get().audioEl;
      if (el && !get().isMuted) el.volume = level / 100;
    },

    setMuted: (v) => {
      set({ isMuted: !!v });
      const el = get().audioEl;
      if (el) el.muted = !!v;
    },

    toggleMute: () => {
      const next = !get().isMuted;
      set({ isMuted: next });
      const el = get().audioEl;
      if (el) el.muted = next;
    },

    setSong: (song) => set({ song }),
    setSource: (src) => {
      set({ src });
      const el = get().audioEl;
      if (el) {
        el.src = src || '';
        if (src) {
          el.preload = 'auto';
        } else {
          try { el.removeAttribute('src'); } catch {}
        }
      }
    },

    setHasUserInteracted: (v) => set({ hasUserInteracted: !!v }),

    setAudioEl: (el) => set({ audioEl: el }),

    seekTo: (s) => {
      const { audioEl, duration } = get();
      const clamped = Math.max(0, Math.min(duration || 0, s || 0));
      if (audioEl) audioEl.currentTime = clamped;
      set({
        currentTime: clamped,
        lastTimeUpdateMs: performance.now()
      });
    },

    play: async () => {
      const el = get().audioEl;
      if (!el) return;
      const proc = get().processor;
      await proc?.resumeContextIfNeeded?.();
      await el.play();
      set({ isPlaying: true });
      proc?.setPlayingState?.(true);
    },

    pause: () => {
      const el = get().audioEl;
      if (!el) return;
      el.pause();
      set({ isPlaying: false });
      const proc = get().processor;
      proc?.setPlayingState?.(false);
    },

    togglePlayPause: async () => {
      const el = get().audioEl;
      if (!el) return;
      if (el.paused) {
        await get().play();
      } else {
        get().pause();
      }
    },

    connectAudioProcessor: async (api) => {
      set({ processor: api || null });
      const el = get().audioEl;
      if (!api || !el) return;
      try {
        await api.connect?.(el);
        set({ processorConnected: true });
      } catch {
        set({ processorConnected: false });
      }
    },

    resumeProcessorContext: async () => {
      const proc = get().processor;
      await proc?.resumeContextIfNeeded?.();
    },
  })),
);

/**
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

  // Attempt to connect processor if available and not yet connected
  if (st.processor && !st.processorConnected) {
    Promise.resolve(st.processor.connect?.(el))
      .then(() => useMusicPlayerStore.setState({ processorConnected: true }))
      .catch(() => useMusicPlayerStore.setState({ processorConnected: false }));
  }

  // Throttle currentTime updates
  let rafId: number | null = null;
  let pendingTime: number | null = null;

  const onTimeUpdate = () => {
    pendingTime = el.currentTime;

    // Only proceed with the update if we're not already waiting for a rAF
    // or if enough time has passed since the last update
    if (rafId == null) {
      rafId = requestAnimationFrame(() => {
        rafId = null;
        if (pendingTime != null) {
          const state = useMusicPlayerStore.getState();
          const timeSinceLastUpdate = performance.now() - state.lastTimeUpdateMs;

          // Only update if:
          // 1. More than the throttle time has passed, or
          // 2. We're at the start/end boundaries, or
          // 3. We're seeking (current and pending times differ significantly)
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

  // When seeking, we want to update immediately
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
  const store = useMusicPlayerStore.getState();

  // Initialize the global processor
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
  await store.connectAudioProcessor(processorApi);

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

// Selector hooks with optimizations for consumers (read-only)
export const usePlayerDuration = () => useMusicPlayerStore(s => s.duration);

// Throttled hook for current time to prevent excessive re-renders
export function usePlayerCurrentTime() {
  // Get the raw value from the store
  const currentTime = useMusicPlayerStore(s => s.currentTime);
  const isPlaying = useMusicPlayerStore(s => s.isPlaying);

  // Always create state and refs regardless of playing state
  const [throttledTime, setThrottledTime] = useState(currentTime);
  const lastUpdateRef = useRef(performance.now());
  const currentTimeRef = useRef(currentTime);

  // Always keep the ref updated with latest value
  currentTimeRef.current = currentTime;

  // Set up a timer to update the throttled value periodically during playback
  useEffect(() => {
    // Only activate the interval when playing
    if (!isPlaying) {
      // When not playing, sync the throttled time with the actual time
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

  // When seeking, update immediately
  useEffect(() => {
    const diff = Math.abs(throttledTime - currentTime);
    if (diff > 0.5) {  // If difference is significant (seeking)
      setThrottledTime(currentTime);
      lastUpdateRef.current = performance.now();
    }
  }, [currentTime, throttledTime]);

  // Return the throttled time whether playing or not
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

// Action accessors for consumers (imperative controls)
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
    // Add these new actions
    initializeGlobalAudioProcessor,
    resetGlobalAudioProcessor,
  };
}
