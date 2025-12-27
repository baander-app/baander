import { useState } from 'react';
import {
  NextButton,
  PlayPauseButton,
  PreviousButton,
} from '@/app/modules/library-music-player/components/player-buttons/player-buttons.tsx';
import { usePlayerActions } from '@/app/modules/library-music-player/store';
import { QueueModal } from '@/app/modules/library-music-player/components/queue-modal/queue-modal';
import { ListBulletIcon, LockOpen2Icon } from '@radix-ui/react-icons';
import { IconButton } from '@radix-ui/themes';

import styles from './player-controls.module.scss';

export interface PlayerControlsProps {
  isPlaying: boolean;
  togglePlayPause: () => void;
}
export function PlayerControls({ isPlaying, togglePlayPause }: PlayerControlsProps) {
  const { playNext, playPrevious } = usePlayerActions();
  const [isQueueModalOpen, setIsQueueModalOpen] = useState(false);

  return (
    <div className={styles.playerControls}>
      {/* Queue Button */}
      <IconButton
        size="2"
        variant="ghost"
        aria-label="Queue"
        onClick={() => setIsQueueModalOpen(true)}
        className={styles.queueButton}
      >
        <ListBulletIcon width={20} height={20} />
      </IconButton>

      <PreviousButton onClick={() => playPrevious()}/>

      <PlayPauseButton
        isPlaying={isPlaying}
        onClick={() => togglePlayPause()}
      />

      <NextButton onClick={() => playNext()}/>

      {/* Queue Modal */}
      <QueueModal
        isOpen={isQueueModalOpen}
        onClose={() => setIsQueueModalOpen(false)}
      />
    </div>
  )
}