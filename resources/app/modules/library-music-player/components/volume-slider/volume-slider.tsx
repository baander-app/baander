import { useAudioPlayer } from '@/modules/library-music-player/providers/audio-player-provider.tsx';
import { Flex, IconButton, Slider } from '@radix-ui/themes';
import { Iconify } from '@/ui/icons/iconify.tsx';
import { MUSIC_CONTROL_ICON_SIZE } from '@/modules/library-music-player/constants.ts';
import { useState, useCallback } from 'react';
import styles from './volume-slider.module.scss';

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
  const {
    volume,
    setCurrentVolume,
    isMuted,
    toggleMuteUnmute,
  } = useAudioPlayer();

  const [isHovering, setIsHovering] = useState(false);

  const handleVolumeChange = useCallback((value: number[]) => {
    setCurrentVolume(value[0]);
  }, [setCurrentVolume]);

  const handleKeyDown = useCallback((e: React.KeyboardEvent) => {
    if (e.key === ' ') {
      e.preventDefault();
      toggleMuteUnmute();
    }
  }, [toggleMuteUnmute]);

  const displayVolume = isMuted ? 0 : volume;

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
        onClick={toggleMuteUnmute}
        variant="ghost"
        size="2"
        className={styles.iconButton}
      >
        <Iconify
          fontSize={MUSIC_CONTROL_ICON_SIZE}
          icon={getVolumeIcon(isMuted, volume)}
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
          size="2"
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
