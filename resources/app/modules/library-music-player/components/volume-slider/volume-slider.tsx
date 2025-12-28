import { Flex, IconButton, Slider } from '@radix-ui/themes';
import { Iconify } from '@/app/ui/icons/iconify.tsx';
import { MUSIC_CONTROL_ICON_SIZE } from '@/app/modules/library-music-player/constants.ts';
import { useState, useCallback } from 'react';
import styles from './volume-slider.module.scss';
import { usePlayerActions, usePlayerIsMuted, usePlayerVolumePercent } from '@/app/modules/library-music-player/store';

function getVolumeIcon(isMuted: boolean, volume: number): string {
  if (isMuted || volume === 0) {
    return 'raphael:volume0';
  }

  if (volume > 0 && volume <= 39) {
    return 'raphael:volume1';
  }

  if (volume >= 40 && volume <= 69) {
    return 'raphael:volume2';
  }

  return 'raphael:volume3';
}

export function VolumeSlider() {
  const isMuted = usePlayerIsMuted();
  const volumePercent = usePlayerVolumePercent();
  const {
    setVolumePercent,
    toggleMute
  } = usePlayerActions();

  const [isHovering, setIsHovering] = useState(false);

  const handleVolumeChange = useCallback((value: number[]) => {
    setVolumePercent(value[0]);
  }, [setVolumePercent]);

  const handleKeyDown = useCallback((e: React.KeyboardEvent) => {
    if (e.key === ' ') {
      e.preventDefault();
      toggleMute();
    }
  }, [toggleMute]);

  const displayVolume = isMuted ? 0 : volumePercent;

  return (
    <Flex
      width="120px"
      mr="xs"
      align="center"
      justify="center"
      gap="2"
      className={styles.container}
    >
      <IconButton
        onClick={toggleMute}
        variant="ghost"
        size="2"
        className={styles.iconButton}
      >
        <Iconify
          fontSize={MUSIC_CONTROL_ICON_SIZE}
          icon={getVolumeIcon(isMuted, volumePercent)}
        />
      </IconButton>

      <div
        className={styles.sliderContainer}
        onMouseEnter={() => setIsHovering(true)}
        onMouseLeave={() => setIsHovering(false)}
        onKeyDown={handleKeyDown}
      >
        <Slider
          value={[displayVolume]}
          onValueChange={handleVolumeChange}
          max={100}
          step={1}
          size="1"
          className={styles.slider}
          aria-label="Volume"
        />

        {isHovering && (
          <div
            className={styles.tooltip}
            style={{
              left: `${displayVolume}%`,
            }}
          >
            {displayVolume}%
          </div>
        )}
      </div>
    </Flex>
  );
}
