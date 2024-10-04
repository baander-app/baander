import React, {
  MutableRefObject,
  ReactEventHandler,
  useCallback,
  useContext,
  useEffect,
  useRef,
  useState,
} from 'react';
import { noop } from '@/utils/noop.ts';
import { useMusicSource } from '@/providers/music-source-provider';
import { notifications } from '@mantine/notifications';
import { SongResource } from '@/api-client/requests';

interface AudioPlayerContextType {
  audioRef: MutableRefObject<HTMLAudioElement>;
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
  audioRef: null as unknown as MutableRefObject<HTMLAudioElement>,
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
  const {
    setAudioRef,
    authenticatedSource,
  } = useMusicSource();

  const audioRef = useRef<HTMLAudioElement>(new Audio());

  const [isPlaying, setIsPlaying] = useState(false);
  const [duration, setDuration] = useState(0);
  const [isReady, setIsReady] = useState(false);
  const [currentProgress, setCurrentProgress] = useState(0);
  const [buffered, setBuffered] = useState(0);
  const [isMuted, setIsMuted] = useState(false);
  const [volume, setVolume] = useState(1);
  const [currentVolume, setCurrentVolume] = useState(100);
  const [song, setSong] = useState<SongResource | null>(null);

  const togglePlayPause = () => {
    if (isPlaying) {
      setIsPlaying(false);
    } else if (isReady) {
      setIsPlaying(true);
    }
  };

  const toggleMuteUnmute = () => {
    if (isMuted) {
      unmute();
    } else {
      mute();
    }
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

  const mute = useCallback(() => {
    if (audioRef.current) {
      audioRef.current.volume = 0;
      setIsMuted(true);
    }
  }, [audioRef]);

  const unmute = useCallback(() => {
    if (audioRef.current) {
      audioRef.current.volume = currentVolume / 100;
      setIsMuted(false);
    }
  }, [audioRef, currentVolume]);

  useEffect(() => {
    const handleCanPlay = () => {
      setIsReady(true);
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
  }, []);

  useEffect(() => {
    if (audioRef.current && currentVolume) {
      audioRef.current.volume = currentVolume / 100;
    }
  }, [audioRef.current, currentVolume]);

  useEffect(() => {
    if (audioRef.current) {
      setAudioRef(audioRef);
    }
  }, [audioRef, setAudioRef]);

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
      currentAudioRef.pause();
    };
  }, []);

  useEffect(() => {
    if (isPlaying && isReady) {
      audioRef.current.play().catch((e) => {
        notifications.show({
          title: 'Audio player error',
          message: e?.message ?? 'Unable to autoplay song',
        });
      });
    } else {
      audioRef.current.pause();
    }
  }, [isPlaying, isReady]);

  useEffect(() => {
    if (!authenticatedSource) {
      return;
    }

    if (!audioRef.current) {
      audioRef.current = new Audio(authenticatedSource);
    } else if (authenticatedSource) {
      audioRef.current.pause();
      audioRef.current.src = authenticatedSource;
    }

    audioRef.current.volume = volume / 100;

    audioRef.current.preload = 'auto';
    // @ts-ignore
    audioRef.current.ondurationchange = (e) => setDuration(e.currentTarget.duration);
    // @ts-ignore
    audioRef.current.ontimeupdate = (e) => handleTimeUpdate(e);
    // @ts-ignore
    audioRef.current.onprogress = (e) => handleBufferProgress(e);
    audioRef.current.play().then(() => {
      setIsPlaying(true);
    })
  }, [authenticatedSource]);

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
