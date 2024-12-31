import {
  LyricsButton,
  VisualizerButton,
} from '@/modules/library-music-player/components/player-buttons/player-buttons.tsx';
import { useDisclosure } from '@mantine/hooks';
import { Waveform } from '@/ui/waveform/waveform.tsx';
import styles from './player-meta-controls.module.scss';
import { SongResource } from '@/api-client/requests';
import { VolumeSlider } from '@/modules/library-music-player/components/volume-slider/volume-slider.tsx';
import { useLyrics } from '@/ui/lyrics-viewer/providers/lyrics-provider.tsx';
import { LyricsViewer } from '@/ui/lyrics-viewer/lyrics-viewer.tsx';
import { Affix } from '@mantine/core';
import { useEffect } from 'react';

export interface PlayerMetaControlsProps {
  song?: SongResource;
}

export function PlayerMetaControls({ song }: PlayerMetaControlsProps) {
  const [showWaveform, waveformHandlers] = useDisclosure(false);
  const [showLyrics, lyricHandlers] = useDisclosure(false);
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
          aria-disabled={song?.lyricsExist ?? false}
          onClick={() => {
            song?.lyrics && setLyrics(song.lyrics);
            lyricHandlers.toggle();
          }}
        />
      </div>

      {showWaveform && (
        <Waveform key="waveform" onClose={() => waveformHandlers.close()} />
      )}

      {showLyrics && (
        <Affix position={{ right: 20, bottom: 90 }}>
          <LyricsViewer key="lyrics" />
        </Affix>
      )}
    </>
  );
}