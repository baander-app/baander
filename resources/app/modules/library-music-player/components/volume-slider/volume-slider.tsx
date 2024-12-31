import { useAudioPlayer } from '@/modules/library-music-player/providers/audio-player-provider.tsx';
import { UnstyledButton, Slider, useMantineTheme, Flex } from '@mantine/core';
import { Iconify } from '@/ui/icons/iconify.tsx';
import { MUSIC_CONTROL_ICON_SIZE } from '@/modules/library-music-player/constants.ts';

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

  const theme = useMantineTheme();


  return (
    <Flex w={100} mr="xs" align="center" justify="center">
      <UnstyledButton onClick={() => toggleMuteUnmute()}>
        <Iconify fontSize={MUSIC_CONTROL_ICON_SIZE} color={theme.colors.gray[7]} icon={getVolumeIcon(isMuted, volume)}/>
      </UnstyledButton>

      <Slider
        min={0}
        max={100}
        size="sm"
        value={volume}
        onChange={value => setCurrentVolume(value)}
        miw={100}
        mb={3}
      />
    </Flex>
  );
}