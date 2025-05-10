import { useAudioPlayer } from '@/modules/library-music-player/providers/audio-player-provider.tsx';
import { Slider, Flex, IconButton } from '@radix-ui/themes';
import { Iconify } from '@/ui/icons/iconify.tsx';
import { MUSIC_CONTROL_ICON_SIZE } from '@/modules/library-music-player/constants.ts';
import { ChangeEvent } from 'react';

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

  const handleChange = (e: ChangeEvent<HTMLInputElement>) => {
    e.preventDefault();
    setCurrentVolume(Number(e.target.value));
  }

  return (
    <Flex width="120px" mr="xs" align="center" justify="center">
      <IconButton onClick={() => toggleMuteUnmute()} variant="ghost">
        <Iconify fontSize={MUSIC_CONTROL_ICON_SIZE}  icon={getVolumeIcon(isMuted, volume)}/>
      </IconButton>


      <Slider
        min={0}
        max={100}
        // size="sm"
        defaultValue={[volume]}
        onChange={handleChange}
      />
    </Flex>
  );
}