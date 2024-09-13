import React, { MutableRefObject, ReactEventHandler, useContext, useEffect, useRef, useState } from 'react';
import { noop } from '@/utils/noop.ts';
import { useMusicSource } from '@/providers/music-source-provider';
import { notifications } from '@mantine/notifications';

interface AudioPlayerContextType {
  audioRef: MutableRefObject<HTMLAudioElement>;
  isPlaying: boolean;
  duration: number;
  currentProgress: number;
  setCurrentProgress: (value: number) => void;
  buffered: number;
  togglePlayPause: () => void;
}

export const AudioPlayerContext = React.createContext<AudioPlayerContextType>({
  audioRef: null as unknown as MutableRefObject<HTMLAudioElement>,
  isPlaying: false,
  duration: 0,
  currentProgress: 0,
  setCurrentProgress: () => noop(),
  buffered: 0,
  togglePlayPause: () => noop(),
});
AudioPlayerContext.displayName = 'AudioPlayerContext';

export function AudioPlayerContextProvider({ children }: { children: React.ReactNode }) {
  const musicSource = useMusicSource();

  const audioRef = useRef<HTMLAudioElement>(new Audio());

  const [isPlaying, setIsPlaying] = useState(false);
  const [duration, setDuration] = useState(0);
  const [isReady, setIsReady] = useState(false);
  const [currentProgress, setCurrentProgress] = useState<number>(0);
  const [buffered, setBuffered] = useState(0);

  const togglePlayPause = () => {
    if (isPlaying) {
      setIsPlaying(false);
    } else if (isReady) {
      setIsPlaying(true);
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

    // console.log('timeupdate', audio.currentTime);

    setCurrentProgress(audio.currentTime);
    handleBufferProgress(e);
  };

  useEffect(() => {
    if (audioRef.current) {
      musicSource.setAudioRef(audioRef);
    }
  }, [audioRef.current, musicSource.setAudioRef]);

  useEffect(() => {
    return () => {
      audioRef.current.pause();
    };
  }, []);

  useEffect(() => {
    if (isPlaying) {
      audioRef.current.play();
    } else {
      audioRef.current.pause();
    }
  }, [isPlaying]);

  useEffect(() => {
    if (!musicSource.authenticatedSource) {
      return;
    }

    if (!audioRef.current) {
      audioRef.current = new Audio(musicSource.authenticatedSource);
    } else {
      audioRef.current.pause();
      audioRef.current.src = musicSource.authenticatedSource;
    }

    audioRef.current.preload = 'auto';
    audioRef.current.oncanplay = () => setIsReady(true);
    // @ts-ignore
    audioRef.current.ondurationchange = (e) => setDuration(e.currentTarget.duration);
    // @ts-ignore
    audioRef.current.ontimeupdate = (e) => handleTimeUpdate(e);
    // @ts-ignore
    audioRef.current.onprogress = (e) => handleBufferProgress(e);

    audioRef.current.play()
            .then(() => {
              setIsPlaying(true);
            }).catch(() => {
      notifications.show({
        title: 'Audio player error',
        message: 'Unable to autoplay',
      });
    });
  }, [musicSource.authenticatedSource]);

  return (
    <AudioPlayerContext.Provider
      value={{
        audioRef,
        isPlaying,
        duration,
        currentProgress,
        setCurrentProgress,
        buffered,
        togglePlayPause,
      }}
    >{children}</AudioPlayerContext.Provider>
  );
}

export function useAudioPlayer() {
  return useContext(AudioPlayerContext);
}
