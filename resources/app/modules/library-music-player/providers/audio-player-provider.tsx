import React, { ReactEventHandler, RefObject, useCallback, useContext, useEffect, useRef, useState } from 'react';
import { noop } from '@/utils/noop.ts';
import { useMusicSource } from '@/providers/music-source-provider';
import { useAppDispatch } from '@/store/hooks.ts';
import { createNotification } from '@/store/notifications/notifications-slice.ts';
import { globalAudioProcessor } from '@/services/global-audio-processor-service.ts';
import { SongResource } from '@/libs/api-client/gen/models';
import { useOpenTelemetry } from '@/providers/open-telemetry-provider.tsx';

interface AudioPlayerContextType {
  audioRef: RefObject<HTMLAudioElement>;
  isPlaying: boolean;
  play: () => void;
  duration: number;
  currentProgress: number;
  setCurrentProgress: (value: number) => void;
  buffered: number;
  togglePlayPause: () => void;
  volume: number;
  setCurrentVolume: (value: number) => void;
  isMuted: boolean;
  mute: () => void;
  unmute: () => void;
  toggleMuteUnmute: () => void;
  song: SongResource | null;
  setSong: (song: SongResource | null) => void;
}

export const AudioPlayerContext = React.createContext<AudioPlayerContextType>({
  audioRef: null as unknown as RefObject<HTMLAudioElement>,
  isPlaying: false,
  play: () => noop(),
  duration: 0,
  currentProgress: 0,
  setCurrentProgress: () => noop(),
  buffered: 0,
  togglePlayPause: () => noop(),
  volume: 100,
  setCurrentVolume: () => noop(),
  isMuted: false,
  mute: () => noop(),
  unmute: () => noop(),
  toggleMuteUnmute: () => noop(),
  song: null,
  setSong: () => noop(),
});
AudioPlayerContext.displayName = 'AudioPlayerContext';

export function AudioPlayerContextProvider({ children }: { children: React.ReactNode }) {
  const dispatch = useAppDispatch();
  const {
    setAudioRef,
    authenticatedSource,
  } = useMusicSource();
  const { tracer, meter, errorCounter } = useOpenTelemetry();

  // Create audio-specific metrics
  const audioPlayCounter = meter.createCounter('audio_play_count', {
    description: 'Number of times audio playback was started',
  });

  const audioPauseCounter = meter.createCounter('audio_pause_count', {
    description: 'Number of times audio playback was paused',
  });

  const audioLoadDurationHistogram = meter.createHistogram('audio_load_duration', {
    description: 'Time taken to load audio source',
    unit: 'ms',
  });

  const audioBufferingHistogram = meter.createHistogram('audio_buffering_duration', {
    description: 'Time spent buffering audio',
    unit: 'ms',
  });

  const audioVolumeHistogram = meter.createHistogram('audio_volume_changes', {
    description: 'Audio volume level changes',
    unit: 'percent',
  });

  const audioRef = useRef<HTMLAudioElement>(new Audio());
  const [processorConnected, setProcessorConnected] = useState(false);
  const [loadStartTime, setLoadStartTime] = useState<number | null>(null);
  const [bufferStartTime, setBufferStartTime] = useState<number | null>(null);

  const [hasUserInteracted, setHasUserInteracted] = useState(false);

  const [isPlaying, setIsPlaying] = useState(false);
  const [duration, setDuration] = useState(0);
  const [isReady, setIsReady] = useState(false);
  const [currentProgress, setCurrentProgress] = useState(0);
  const [buffered, setBuffered] = useState(0);
  const [isMuted, setIsMuted] = useState(false);
  const [volume, setVolume] = useState(1);
  const [currentVolume, setCurrentVolume] = useState(100);
  const [song, setSong] = useState<SongResource | null>(null);

  useEffect(() => {
    const handleUserInteraction = () => {
      const span = tracer.startSpan('user_interaction_detected');
      span.setAttributes({
        'audio.interaction.type': 'first_user_interaction',
        'audio.ready_state': audioRef.current?.readyState || 0,
      });

      setHasUserInteracted(true);
      span.end();

      // Remove listeners after first interaction
      document.removeEventListener('click', handleUserInteraction);
      document.removeEventListener('keydown', handleUserInteraction);
      document.removeEventListener('touchstart', handleUserInteraction);
    };

    document.addEventListener('click', handleUserInteraction);
    document.addEventListener('keydown', handleUserInteraction);
    document.addEventListener('touchstart', handleUserInteraction);

    return () => {
      document.removeEventListener('click', handleUserInteraction);
      document.removeEventListener('keydown', handleUserInteraction);
      document.removeEventListener('touchstart', handleUserInteraction);
    };
  }, [tracer]);

  // Initialize global audio processor only once when audio element is created
  useEffect(() => {
    if (audioRef.current && !processorConnected) {
      const span = tracer.startSpan('audio_processor_initialization');
      console.log('Initializing global audio processor...');

      globalAudioProcessor.initialize();
      globalAudioProcessor.connectAudioElement(audioRef.current)
        .then(() => {
          console.log('Audio processor connected successfully');
          span.setStatus({ code: 1 });
          span.setAttributes({
            'audio.processor.connected': true,
            'audio.processor.initialization_successful': true,
          });
          setProcessorConnected(true);
        })
        .catch((error) => {
          console.warn('Failed to connect audio processor:', error);
          span.recordException(error);
          span.setStatus({ code: 2, message: error.message });
          span.setAttributes({
            'audio.processor.connected': false,
            'audio.processor.initialization_successful': false,
            'audio.processor.error': error.message,
          });

          errorCounter.add(1, {
            type: 'audio_processor_connection_failed',
            message: error.message,
          });

          // Still mark as connected to prevent retries
          setProcessorConnected(true);
        })
        .finally(() => {
          span.end();
        });
    }
  }, [processorConnected, tracer, errorCounter]);

  const playWithContextResume = useCallback(async () => {
    if (!audioRef.current || !isReady) {
      return;
    }

    const span = tracer.startSpan('audio_play_with_context_resume');
    const playStartTime = performance.now();

    try {
      span.setAttributes({
        'audio.song.id': song?.id || 'unknown',
        'audio.song.title': song?.title || 'unknown',
        'audio.current_time': audioRef.current.currentTime,
        'audio.duration': audioRef.current.duration || 0,
        'audio.volume': audioRef.current.volume,
        'audio.ready_state': audioRef.current.readyState,
      });

      // Try to resume the audio processor context if needed
      if (processorConnected && globalAudioProcessor) {
        await globalAudioProcessor.resumeContextIfNeeded?.();
      }

      await audioRef.current.play();

      const playDuration = performance.now() - playStartTime;
      audioPlayCounter.add(1, {
        song_id: song?.id || 'unknown',
        song_title: song?.title || 'unknown',
        volume_level: Math.round(audioRef.current.volume * 100).toString(),
      });

      span.setAttributes({
        'audio.play.duration_ms': playDuration,
        'audio.play.successful': true,
      });

      setIsPlaying(true);
      span.setStatus({ code: 1 });
    } catch (error) {
      const playDuration = performance.now() - playStartTime;
      console.warn('Play failed:', error);

      span.recordException(error as Error);
      span.setStatus({ code: 2, message: (error as Error).message });
      span.setAttributes({
        'audio.play.duration_ms': playDuration,
        'audio.play.successful': false,
        'audio.play.error': (error as Error).message,
      });

      errorCounter.add(1, {
        type: 'audio_play_failed',
        message: (error as Error).message,
        song_id: song?.id || 'unknown',
      });

      dispatch(createNotification({
        type: 'warning',
        title: 'Playback requires interaction',
        message: 'Please click the play button to start playback',
        toast: true,
      }));
    } finally {
      span.end();
    }
  }, [isReady, dispatch, processorConnected, tracer, audioPlayCounter, errorCounter, song]);

  const togglePlayPause = useCallback(() => {
    const span = tracer.startSpan('audio_toggle_play_pause');

    span.setAttributes({
      'audio.current_state': isPlaying ? 'playing' : 'paused',
      'audio.target_state': isPlaying ? 'paused' : 'playing',
      'audio.song.id': song?.id || 'unknown',
    });

    if (isPlaying) {
      audioPauseCounter.add(1, {
        song_id: song?.id || 'unknown',
        current_time: audioRef.current?.currentTime?.toString() || '0',
      });
      setIsPlaying(false);
      span.setAttributes({ 'audio.action_taken': 'pause' });
    } else if (isReady) {
      span.setAttributes({ 'audio.action_taken': 'play' });
      playWithContextResume();
    } else {
      span.setAttributes({
        'audio.action_taken': 'none',
        'audio.reason': 'not_ready'
      });
    }

    span.end();
  }, [isPlaying, isReady, playWithContextResume, tracer, audioPauseCounter, song]);

  const toggleMuteUnmute = () => {
    const span = tracer.startSpan('audio_toggle_mute');

    span.setAttributes({
      'audio.current_mute_state': isMuted,
      'audio.target_mute_state': !isMuted,
      'audio.volume_before': currentVolume,
    });

    if (isMuted) {
      unmute();
      span.setAttributes({ 'audio.action_taken': 'unmute' });
    } else {
      mute();
      span.setAttributes({ 'audio.action_taken': 'mute' });
    }

    span.end();
  };

  const handleBufferProgress: ReactEventHandler<HTMLAudioElement> = (e) => {
    const audio = e.currentTarget;
    const dur = audio.duration;
    if (dur > 0) {
      for (let i = 0; i < audio.buffered.length; i++) {
        if (
          audio.buffered.start(audio.buffered.length - 1 - i) < audio.currentTime
        ) {
          const bufferedLength = audio.buffered.end(
            audio.buffered.length - 1 - i,
          );
          setBuffered(bufferedLength);

          // Track buffering metrics
          const bufferPercentage = (bufferedLength / dur) * 100;
          if (bufferStartTime && bufferPercentage > 10) {
            const bufferDuration = performance.now() - bufferStartTime;
            audioBufferingHistogram.record(bufferDuration, {
              buffer_percentage: Math.round(bufferPercentage).toString(),
              song_id: song?.id || 'unknown',
            });
            setBufferStartTime(null);
          }
          break;
        }
      }
    }
  };

  const handleTimeUpdate: ReactEventHandler<HTMLAudioElement> = (e) => {
    const audio = e.currentTarget;
    setCurrentProgress(audio.currentTime);
    handleBufferProgress(e);
  };

  const mute = () => {
    if (audioRef.current) {
      const span = tracer.startSpan('audio_mute');
      span.setAttributes({
        'audio.volume_before': audioRef.current.volume,
        'audio.volume_after': 0,
      });

      audioRef.current.volume = 0;
      setIsMuted(true);
      span.end();
    }
  };

  const unmute = useCallback(() => {
    if (audioRef.current) {
      const span = tracer.startSpan('audio_unmute');
      const targetVolume = currentVolume / 100;

      span.setAttributes({
        'audio.volume_before': 0,
        'audio.volume_after': targetVolume,
        'audio.target_volume_percent': currentVolume,
      });

      audioRef.current.volume = targetVolume;
      setIsMuted(false);
      span.end();
    }
  }, [currentVolume, tracer]);

  useEffect(() => {
    const handleCanPlay = () => {
      const span = tracer.startSpan('audio_can_play');

      if (loadStartTime) {
        const loadDuration = performance.now() - loadStartTime;
        audioLoadDurationHistogram.record(loadDuration, {
          song_id: song?.id || 'unknown',
          song_title: song?.title || 'unknown',
        });
        setLoadStartTime(null);
      }

      span.setAttributes({
        'audio.ready_state': audioRef.current?.readyState || 0,
        'audio.duration': audioRef.current?.duration || 0,
        'audio.song.id': song?.id || 'unknown',
      });

      setIsReady(true);
      span.end();
    };

    const currentAudioRef = audioRef.current;
    if (currentAudioRef) {
      currentAudioRef.addEventListener('canplay', handleCanPlay);
    }

    return () => {
      if (currentAudioRef) {
        currentAudioRef.removeEventListener('canplay', handleCanPlay);
      }
    };
  }, [tracer, audioLoadDurationHistogram, loadStartTime, song]);

  useEffect(() => {
    if (audioRef.current && currentVolume) {
      const span = tracer.startSpan('audio_volume_change');
      const newVolume = currentVolume / 100;

      span.setAttributes({
        'audio.volume_before': audioRef.current.volume,
        'audio.volume_after': newVolume,
        'audio.volume_percent': currentVolume,
      });

      audioVolumeHistogram.record(currentVolume, {
        song_id: song?.id || 'unknown',
        muted: isMuted.toString(),
      });

      audioRef.current.volume = newVolume;
      span.end();
    }
  }, [currentVolume, tracer, audioVolumeHistogram, song, isMuted]);

  useEffect(() => {
    if (audioRef.current) {
      setAudioRef(audioRef);
    }
  }, [setAudioRef]);

  useEffect(() => {
    if (currentVolume) {
      setVolume(currentVolume);
    }
  }, [currentVolume]);

  useEffect(() => {
    const currentAudioRef = audioRef.current;
    if (currentAudioRef) {
      currentAudioRef.volume = currentVolume / 100;
    }
  }, [volume]);

  useEffect(() => {
    const currentAudioRef = audioRef.current;
    return () => {
      const span = tracer.startSpan('audio_player_cleanup');
      span.setAttributes({
        'audio.final_state': currentAudioRef.paused ? 'paused' : 'playing',
        'audio.final_time': currentAudioRef.currentTime,
      });

      currentAudioRef.pause();
      span.end();
      // Note: Don't destroy the global processor here as it should persist
    };
  }, [tracer]);

  useEffect(() => {
    if (isPlaying && isReady) {
      playWithContextResume().catch((e) => {
        errorCounter.add(1, {
          type: 'audio_autoplay_failed',
          message: e?.message ?? 'Unable to autoplay song',
          song_id: song?.id || 'unknown',
        });

        dispatch(createNotification({
          type: 'error',
          title: 'Audio player error',
          message: e?.message ?? 'Unable to autoplay song',
          toast: true,
        }));
      });
    } else {
      audioRef.current.pause();
    }
  }, [isPlaying, isReady, playWithContextResume, dispatch, errorCounter, song]);

  useEffect(() => {
    if (!authenticatedSource) {
      return;
    }

    const span = tracer.startSpan('audio_source_change');
    setLoadStartTime(performance.now());
    setBufferStartTime(performance.now());

    // Don't create a new audio element if we already have one
    if (audioRef.current.src !== authenticatedSource) {
      console.log('Setting new audio source:', authenticatedSource);

      span.setAttributes({
        'audio.source.previous': audioRef.current.src || 'none',
        'audio.source.new': authenticatedSource,
        'audio.song.id': song?.id || 'unknown',
        'audio.song.title': song?.title || 'unknown',
      });

      audioRef.current.pause();
      audioRef.current.src = authenticatedSource;

      // Reset the processor connection attempt flag when source changes
      // This allows the processor to attempt connection again if needed
      if (!processorConnected) {
        globalAudioProcessor.reset();
      }
    }

    audioRef.current.volume = volume / 100;
    audioRef.current.preload = 'auto';

    // @ts-ignore
    audioRef.current.ondurationchange = (e) => {
      const duration = e.currentTarget.duration;
      setDuration(duration);

      const durationSpan = tracer.startSpan('audio_duration_change');
      durationSpan.setAttributes({
        'audio.duration': duration,
        'audio.song.id': song?.id || 'unknown',
      });
      durationSpan.end();
    };
    // @ts-ignore
    audioRef.current.ontimeupdate = (e) => handleTimeUpdate(e);
    // @ts-ignore
    audioRef.current.onprogress = (e) => handleBufferProgress(e);

    // Only autoplay if user has interacted
    if (hasUserInteracted) {
      audioRef.current.play().then(() => {
        setIsPlaying(true);
        span.setAttributes({ 'audio.autoplay.successful': true });
      }).catch((error) => {
        console.warn('Autoplay failed:', error);
        span.recordException(error);
        span.setAttributes({
          'audio.autoplay.successful': false,
          'audio.autoplay.error': error.message,
        });

        errorCounter.add(1, {
          type: 'audio_autoplay_failed',
          message: error.message,
          song_id: song?.id || 'unknown',
        });
      });
    }

    span.end();
  }, [authenticatedSource, volume, processorConnected, hasUserInteracted, tracer, errorCounter, song]);

  return (
    <AudioPlayerContext.Provider
      value={{
        audioRef,
        isPlaying,
        play: togglePlayPause,
        duration,
        currentProgress,
        setCurrentProgress,
        buffered,
        togglePlayPause,
        volume,
        setCurrentVolume,
        isMuted,
        mute,
        unmute,
        toggleMuteUnmute,
        song,
        setSong,
      }}
    >{children}</AudioPlayerContext.Provider>
  );
}

export function useAudioPlayer() {
  return useContext(AudioPlayerContext);
}