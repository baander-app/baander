import { useAudioPlayer } from '@/modules/library-music-player/providers/audio-player-provider.tsx';
import { Flex, IconButton, Slider } from '@radix-ui/themes';
import { Iconify } from '@/ui/icons/iconify.tsx';
import { MUSIC_CONTROL_ICON_SIZE } from '@/modules/library-music-player/constants.ts';
import { useState, useCallback } from 'react';

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
      style={{ position: 'relative' }}
    >
      <IconButton
        onClick={toggleMuteUnmute}
        variant="ghost"
        size="2"
        style={{
          transition: 'transform 0.2s ease',
          cursor: 'pointer'
        }}
        onMouseEnter={(e) => {
          e.currentTarget.style.transform = 'scale(1.1)';
        }}
        onMouseLeave={(e) => {
          e.currentTarget.style.transform = 'scale(1)';
        }}
      >
        <Iconify
          fontSize={MUSIC_CONTROL_ICON_SIZE}
          icon={getVolumeIcon(isMuted, volume)}
        />
      </IconButton>

      <div
        style={{
          position: 'relative',
          flex: 1,
          display: 'flex',
          alignItems: 'center'
        }}
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
          style={{
            flex: 1,
            cursor: 'pointer'
          }}
          aria-label="Volume"
        />

        {isHovering && (
          <div
            style={{
              position: 'absolute',
              top: '-35px',
              left: `${displayVolume}%`,
              transform: 'translateX(-50%)',
              background: 'var(--color-panel-solid)',
              color: 'var(--accent-9)',
              padding: '4px 8px',
              borderRadius: 'var(--radius-2)',
              fontSize: '12px',
              fontWeight: 'bold',
              whiteSpace: 'nowrap',
              border: '1px solid var(--accent-6)',
              boxShadow: 'var(--shadow-4)',
              pointerEvents: 'none',
              zIndex: 10,
            }}
          >
            {displayVolume}%
            <div
              style={{
                position: 'absolute',
                top: '100%',
                left: '50%',
                transform: 'translateX(-50%)',
                width: 0,
                height: 0,
                borderLeft: '4px solid transparent',
                borderRight: '4px solid transparent',
                borderTop: '4px solid var(--accent-6)',
              }}
            />
          </div>
        )}
      </div>
    </Flex>
  );
}
