import { BarsMode } from '@/features/feature-equalizer/types.ts';

import styles from './vfd-display.module.scss';
import { VfdSpectrumAnalyzer } from '@/features/feature-equalizer/components/vfd-spectrum.tsx';
import { VfdChannelAnalyzer } from '@/features/feature-equalizer/components/vfd-channel-analyzer.tsx';
import { PlaybackSource } from '@/lib/models/playback-source.ts';

export interface VfdDisplayProps {
  [key: string]: any;
  isEnabled: boolean;
  audioSource: PlaybackSource;
  isMuted: boolean;
  isRepeatEnabled: boolean;
  isShuffleEnabled: boolean;
  isMicrophoneEnabled: boolean;
  leftChannel: number;
  rightChannel: number;
  isStereoEnabled: boolean;
  isKaraokeEnabled: boolean;
  frequencies: number[];
  barsMode: BarsMode;
  frequencyBars: {
    label: string;
    frequencyId: number;
  }[];
}

export function VfdDisplay(props: VfdDisplayProps) {
  const {
    isEnabled,
    audioSource,
    isMuted,
    isRepeatEnabled,
    isShuffleEnabled,
    isMicrophoneEnabled,
    leftChannel,
    rightChannel,
    isStereoEnabled,
    isKaraokeEnabled,
    frequencies,
    frequencyBars,
    barsMode,
    ...rest
  } = props;

  return (
    <div className={styles.vfdDisplayContainer} {...rest}>
      <div className={styles.vfdControls}>
        <p className={`${styles.vfdControl} ${isEnabled && audioSource === PlaybackSource.LIBRARY ? styles.active : ''}`}>
          TAPE
        </p>
        <p className={`${styles.vfdControl} ${isEnabled && audioSource === PlaybackSource.INTERNET_RADIO ? styles.active : ''}`}>
          TUNER
        </p>
        <p className={`${styles.vfdControl} ${isEnabled && audioSource === PlaybackSource.STREAMING ? styles.active : ''}`}>
          AUX
        </p>
        <p className={`${styles.vfdControlRed} ${isEnabled && isMicrophoneEnabled ? styles.active : ''}`}>
          MIC
        </p>
      </div>
      <div className={styles.vfdAnalyzersRow}>
        <VfdSpectrumAnalyzer
          frequencies={frequencies}
          isEnabled={isEnabled && barsMode !== 'off'}
          frequencyBars={frequencyBars}
          barsMode={barsMode}
        />
        <VfdChannelAnalyzer
          left={leftChannel}
          right={rightChannel}
          isEnabled={isEnabled && barsMode !== 'off'}
          barsMode={barsMode}
        />
      </div>
      <div className={styles.vfdControls}>
        <p className={`${styles.vfdControl} ${isEnabled ? styles.active : ''}`}>
          Hi-Fi
        </p>
        <p className={`${styles.vfdControl} ${isEnabled && isStereoEnabled ? styles.active : ''}`}>
          STEREO
        </p>
        <p className={`${styles.vfdControl} ${isEnabled && isRepeatEnabled ? styles.active : ''}`}>
          REPEAT
        </p>
        <p className={`${styles.vfdControl} ${isEnabled && isShuffleEnabled ? styles.active : ''}`}>
          SHUFFLE
        </p>
        <p className={`${styles.vfdControl} ${isEnabled && isMuted ? styles.active : ''}`}>
          MUTING
        </p>
        <p className={`${styles.vfdControl} ${isEnabled && isKaraokeEnabled && isMicrophoneEnabled ? styles.active : ''}`}>
          KARAOKE
        </p>
      </div>
    </div>
  );
}