import {
  LyricsButton,
  VisualizerButton,
} from '@/modules/library-music-player/components/player-buttons/player-buttons.tsx';
import { Waveform } from '@/ui/waveform/waveform.tsx';
import styles from './player-meta-controls.module.scss';
import { SongResource } from '@/api-client/requests';
import { VolumeSlider } from '@/modules/library-music-player/components/volume-slider/volume-slider.tsx';
import { useLyrics } from '@/ui/lyrics-viewer/providers/lyrics-provider.tsx';
import { LyricsViewer } from '@/ui/lyrics-viewer/lyrics-viewer.tsx';
import { useEffect } from 'react';
import { useDisclosure } from '@/hooks/use-disclosure';
import { Box, Button, Flex } from '@radix-ui/themes';
import { Iconify } from '@/ui/icons/iconify.tsx';
import { AudioStatsOverlay, AudioStats } from '@/ui/audio-stats/audio-stats.tsx';
import { useAudioPlayer } from '@/modules/library-music-player/providers/audio-player-provider.tsx';

export interface PlayerMetaControlsProps {
  song?: SongResource;
}

export function PlayerMetaControls({ song }: PlayerMetaControlsProps) {
  const [showWaveform, waveformHandlers] = useDisclosure(false);
  const [showLyrics, lyricHandlers] = useDisclosure(false);
  const [showDebug, debugHandlers] = useDisclosure(false);
  const { setLyrics } = useLyrics();
  const {audioRef} = useAudioPlayer();

  useEffect(() => {

    if (!song?.lyricsExist) {
      lyricHandlers.close();
    }
  }, [song?.lyricsExist]);

  return (
    <>
      <div className={styles.playerMetaControls}>
        <VolumeSlider />

        <VisualizerButton
          isActive={showWaveform}
          onClick={() => waveformHandlers.toggle()}
        />

        <LyricsButton
          aria-disabled={song?.lyricsExist ?? false}
          onClick={() => {
            song?.lyrics && setLyrics(song.lyrics);
            lyricHandlers.toggle();
          }}
        />

        <Button variant="ghost" onClick={() => debugHandlers.toggle()}>
          <Iconify icon="codicon:debug" height={20} />
        </Button>
      </div>

      {showWaveform && (
        <Waveform key="waveform" onClose={() => waveformHandlers.close()} />
      )}

      {showLyrics && (
        <Box style={{ position: 'absolute', right: 20, bottom: 90 }}>
          <LyricsViewer key="lyrics" />
        </Box>
      )}

      {showDebug && (
        <Flex style={{ position: 'absolute', right: 20, bottom: 90 }}>
          <AudioStats audioRef={audioRef} />
        </Flex>
      )}
    </>
  );
}