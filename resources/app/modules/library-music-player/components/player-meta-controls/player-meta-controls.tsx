import {
  EqButton,
  LyricsButton,
} from '@/modules/library-music-player/components/player-buttons/player-buttons.tsx';
import styles from './player-meta-controls.module.scss';
import { VolumeSlider } from '@/modules/library-music-player/components/volume-slider/volume-slider.tsx';
import { useLyrics } from '@/ui/lyrics-viewer/providers/lyrics-provider.tsx';
import { LyricsViewer } from '@/ui/lyrics-viewer/lyrics-viewer.tsx';
import { useEffect } from 'react';
import { useDisclosure } from '@/hooks/use-disclosure';
import { Box } from '@radix-ui/themes';
import { Equalizer } from '@/modules/dsp/equalizer/equalizer.tsx';
import { SongResource } from '@/libs/api-client/gen/models';

export interface PlayerMetaControlsProps {
  song?: SongResource;
}

export function PlayerMetaControls({ song }: PlayerMetaControlsProps) {
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

      {showLyrics && (
        <Box style={{ position: 'absolute', right: 20, bottom: 90 }}>
          <LyricsViewer key="lyrics" />
        </Box>
      )}

      {showEq && (
        <Box style={{ position: 'absolute', right: 20, bottom: 120, zIndex: 100 }}>
          <Equalizer />
        </Box>
      )}

    </>
  );
}
