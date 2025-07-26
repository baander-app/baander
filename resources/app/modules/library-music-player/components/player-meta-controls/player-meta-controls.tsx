import {
  EqButton,
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
import { Box, Button } from '@radix-ui/themes';
import { Iconify } from '@/ui/icons/iconify.tsx';
import { Equalizer } from '@/modules/sony-eq/equalizer.tsx';

export interface PlayerMetaControlsProps {
  song?: SongResource;
}

export function PlayerMetaControls({ song }: PlayerMetaControlsProps) {
  const [showWaveform, waveformHandlers] = useDisclosure(false);
  const [showLyrics, lyricHandlers] = useDisclosure(false);
  const [showEq, eqHandlers] = useDisclosure(false);
  const { setLyrics } = useLyrics();

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
          aria-disabled={song?.lyricsExist === false}
          className={styles.lyrics}
          onClick={() => {
            song?.lyrics && setLyrics(song.lyrics);
            lyricHandlers.toggle();
          }}
        />

        <EqButton
          className={styles.lyrics}
          onClick={() => eqHandlers.toggle()}
          />
      </div>

      {showWaveform && (
        <Waveform key="waveform" onClose={() => waveformHandlers.close()} />
      )}

      {showLyrics && (
        <Box style={{ position: 'absolute', right: 20, bottom: 90 }}>
          <LyricsViewer key="lyrics" />
        </Box>
      )}

      {showEq && (
        <Box style={{ position: 'absolute', right: 20, bottom: 90, zIndex: 100 }}>
          <Equalizer />
        </Box>
      )}
    </>
  );
}