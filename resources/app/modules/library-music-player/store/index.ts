import { create } from 'zustand';
import { subscribeWithSelector } from 'zustand/middleware';

type Song = { publicId: string; title?: string; libraryId: string } | null;

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

    setDuration: (s) => set({ duration: Number.isFinite(s) ? s : 0 }),
    setCurrentTime: (s) => set({ currentTime: Number.isFinite(s) ? s : 0 }),
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
      set({ currentTime: clamped });
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

  // rAF-throttle currentTime updates
  let rafId: number | null = null;
  let pendingTime: number | null = null;

  const onTimeUpdate = () => {
    pendingTime = el.currentTime;
    if (rafId == null) {
      rafId = requestAnimationFrame(() => {
        rafId = null;
        if (pendingTime != null) {
          useMusicPlayerStore.getState().setCurrentTime(pendingTime);
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

// Selector hooks for consumers (read-only)
export const usePlayerDuration = () => useMusicPlayerStore(s => s.duration);
export const usePlayerCurrentTime = () => useMusicPlayerStore(s => s.currentTime);
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
  };
}
