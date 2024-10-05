import styles from './vfd-channel-analyzer.module.scss';
import { BarsMode } from '@/features/feature-equalizer/types.ts';

export interface VfdChannelAnalyzerProps {
  isEnabled: boolean;
  left: number;
  right: number;
  barsMode: BarsMode;
}

export const VfdChannelAnalyzer = (props: VfdChannelAnalyzerProps) => {
  const { isEnabled, left, right, barsMode } = props;

  const isBarActive = (value: number, index: number) => {
    switch (barsMode) {
      case 'bars':
        return value - 50 >= 100 - index * 10;
      case 'pointer':
        return (value - 50 >= 100 - index * 10) && !(value - 50 >= 100 - (index - 1) * 10);
      case 'off':
        return false;
      default:
        return false;
    }
  };

  return (
    <div className={styles.vfdChannels}>
      <div className={styles.vfdSpectrum}>
        <div className={styles.spectrumColumn}>
          {Array.from({ length: 12 }, (_, index) =>
            index < 3 ? (
              <div
                key={index}
                className={`${styles.spectrumBarRed} ${isEnabled && isBarActive(left, index) ? styles.active : ''}`}
              />
            ) : (
              <div
                key={index}
                className={`${styles.spectrumBar} ${isEnabled && isBarActive(left, index) ? styles.active : ''}`}
              />
            ),
          )}
          <div className={`${styles.spectrumBar} ${isEnabled && barsMode === 'bars' ? styles.active : ''}`} />
          <p className={`${styles.barFrequencyDescription} ${isEnabled ? styles.active : ''}`}>
            LEFT
          </p>
        </div>
        <div className={styles.decibelColumn}>
          <p className={`${styles.decibelText} ${isEnabled ? styles.active : ''}`}>+3</p>
          <p className={`${styles.decibelText} ${isEnabled ? styles.active : ''}`}>0</p>
          <p className={`${styles.decibelText} ${isEnabled ? styles.active : ''}`}>-3</p>
          <p className={`${styles.decibelText} ${isEnabled ? styles.active : ''}`}>-5</p>
          <p className={`${styles.decibelText} ${isEnabled ? styles.active : ''}`}>-10</p>
          <p className={`${styles.decibelText} ${isEnabled ? styles.active : ''}`}>-20</p>
          <p className={`${styles.decibelText} ${isEnabled ? styles.active : ''}`}>-∞</p>
          <p className={`${styles.barFrequencyDescription} ${styles.dB} ${isEnabled ? styles.active : ''}`}>
            dB
          </p>
        </div>
        <div className={styles.spectrumColumn}>
          {Array.from({ length: 12 }, (_, index) =>
            index < 3 ? (
              <div
                key={index}
                className={`${styles.spectrumBarRed} ${isEnabled && isBarActive(right, index) ? styles.active : ''}`}
              />
            ) : (
              <div
                key={index}
                className={`${styles.spectrumBar} ${isEnabled && isBarActive(right, index) ? styles.active : ''}`}
              />
            ),
          )}
          <div className={`${styles.spectrumBar} ${isEnabled && barsMode === 'bars' ? styles.active : ''}`} />
          <p className={`${styles.barFrequencyDescription} ${isEnabled ? styles.active : ''}`}>
            RIGHT
          </p>
        </div>
      </div>
    </div>
  );
};