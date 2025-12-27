import {
  NextButton,
  PlayPauseButton,
  PreviousButton,
} from '@/app/modules/library-music-player/components/player-buttons/player-buttons.tsx';
import { usePlayerActions } from '@/app/modules/library-music-player/store';

import styles from './player-controls.module.scss';

export interface PlayerControlsProps {
  isPlaying: boolean;
  togglePlayPause: () => void;
}
export function PlayerControls({ isPlaying, togglePlayPause }: PlayerControlsProps) {
  const { playNext, playPrevious } = usePlayerActions();

  return (
    <div className={styles.playerControls}>
      <PreviousButton onClick={() => playPrevious()}/>

      <PlayPauseButton
        isPlaying={isPlaying}
        onClick={() => togglePlayPause()}
      />

      <NextButton onClick={() => playNext()}/>
    </div>
  )
}