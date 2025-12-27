import { create } from 'zustand';
import { subscribeWithSelector, persist } from 'zustand/middleware';
import { createLogger } from '../../../services/logger';
import { QueueSlice, createQueueSlice } from './slices/queue-slice';
import { PlaybackSlice, createPlaybackSlice } from './slices/playback-slice';
import { AnalysisSlice, createAnalysisSlice } from './slices/analysis-slice';
import { LyricsSlice, createLyricsSlice } from './slices/lyrics-slice';
import { PlaybackModeSlice, createPlaybackModeSlice } from './slices/playback-mode-slice';
import { VolumeSlice, createVolumeSlice } from './slices/volume-slice';
import { TimingSlice, createTimingSlice } from './slices/timing-slice';
import { SourceSlice, createSourceSlice } from './slices/source-slice';
import { ProcessorSlice, createProcessorSlice } from './slices/processor-slice';

const logger = createLogger('MusicPlayerStore');

export type MusicPlayerState = QueueSlice &
  PlaybackSlice &
  AnalysisSlice &
  LyricsSlice &
  PlaybackModeSlice &
  VolumeSlice &
  TimingSlice &
  SourceSlice &
  ProcessorSlice;

export const useMusicPlayerStore = create<MusicPlayerState>()(
  subscribeWithSelector(
    persist(
      (set, get, api) => ({
        ...createQueueSlice(set, get, api),
        ...createPlaybackSlice(set, get, api),
        ...createAnalysisSlice(set, get, api),
        ...createLyricsSlice(set, get, api),
        ...createPlaybackModeSlice(set, get, api),
        ...createVolumeSlice(set, get, api),
        ...createTimingSlice(set, get, api),
        ...createSourceSlice(set, get, api),
        ...createProcessorSlice(set, get, api),

        // Override actions that need cross-slice access
        setIsPlaying: (v) => {
          set({ isPlaying: !!v });
          const proc = get().processor;
          proc?.setPlayingState?.(!!v);
        },

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

        setSource: (src) => {
          set({ src });
          const el = get().audioEl;
          if (el) {
            el.src = src || '';
            if (src) {
              el.preload = 'auto';
              // Connect processor when source is set
              const st = get();
              if (st.processor) {
                logger.debug('Connecting processor to audio element with source');
                Promise.resolve(st.processor.connect?.(el))
                  .then(() => {
                    logger.debug('Processor connection completed');
                    useMusicPlayerStore.setState({ processorConnected: true });
                  })
                  .catch((err) => {
                    logger.error('Failed to connect processor:', err);
                  });
              }
            } else {
              try { el.removeAttribute('src'); } catch {}
            }
          }
        },

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
      }),
      {
        name: 'baander-music-player',
        version: 2,
        migrate: (persistedState: any, version: number) => {
          // Migrate from version 1 (single queue) to version 2 (multi-queue)
          if (version === 0 && persistedState.queue && !persistedState.queues) {
            // Old single queue format - migrate to music queue
            return {
              ...persistedState,
              activeQueueType: 'music',
              queues: {
                music: {
                  items: persistedState.queue || [],
                  currentIndex: persistedState.currentSongIndex ?? -1,
                  currentItemPublicId: persistedState.currentSongPublicId ?? null,
                  source: persistedState.source || 'none',
                  lastUpdated: Date.now(),
                },
                audiobook: {
                  items: [],
                  currentIndex: -1,
                  currentItemPublicId: null,
                  source: 'none',
                  lastUpdated: Date.now(),
                },
                podcast: {
                  items: [],
                  currentIndex: -1,
                  currentItemPublicId: null,
                  source: 'none',
                  lastUpdated: Date.now(),
                },
              },
              // Remove old fields
              queue: undefined,
              currentSongIndex: undefined,
              currentSongPublicId: undefined,
            };
          }
          return persistedState;
        },
        partialize: (state) => ({
          // Persist multi-queue state
          activeQueueType: state.activeQueueType,
          queues: state.queues,
          // Persist current song
          song: state.song,
          // Persist other settings
          playbackMode: state.playbackMode,
          lyricsOffset: state.lyricsOffset,
          volumePercent: state.volumePercent,
          isMuted: state.isMuted,
          // Exclude: analysis (runtime-only), audio element, processor, timing, etc.
        }),
      }
    )
  )
);
