import {
  NextButton,
  PlayPauseButton,
  PreviousButton,
} from '@/modules/library-music-player/components/player-buttons/player-buttons.tsx';
import { useAppDispatch } from '@/store/hooks.ts';
import { playNextSong, playPreviousSong } from '@/store/music/music-player-slice.ts';

import styles from './player-controls.module.scss';

export interface PlayerControlsProps {
  isPlaying: boolean;
  togglePlayPause: () => void;
}
export function PlayerControls({ isPlaying, togglePlayPause }: PlayerControlsProps) {
  const dispatch = useAppDispatch();

  const onPlayNextSong = () => {
    dispatch(playNextSong());
  }

  const onPlayPreviousSong = () => {
    dispatch(playPreviousSong());
  }

  return (
    <div className={styles.playerControls}>
      <PreviousButton onClick={() => onPlayPreviousSong()}/>

      <PlayPauseButton
        isPlaying={isPlaying}
        onClick={() => togglePlayPause()}
      />

      <NextButton onClick={() => onPlayNextSong()}/>
    </div>
  )
}