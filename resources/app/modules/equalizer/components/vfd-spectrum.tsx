import { BarsMode } from '@/modules/equalizer/types.ts';

import styles from './vfd-spectrum.module.scss';

export interface VfdSpectrumAnalyzerProps {
  isEnabled: boolean;
  frequencies: number[];
  frequencyBars: {
    label: string;
    frequencyId: number;
  }[];
  barsMode: BarsMode;
}

export const VfdSpectrumAnalyzer = (props: VfdSpectrumAnalyzerProps) => {
  const { isEnabled, frequencyBars, frequencies, barsMode } = props;

  const isBarActive = (value: number, index: number) => {
    switch (barsMode) {
      case 'bars':
        return value - 100 - (55 - index * 5) >= 100 - index * 10;
      case 'pointer':
        return value - 100 - (55 - index * 5) >= 100 - index * 10 &&
          !(value - 100 - (55 - (index - 1) * 5) >= 100 - (index - 1) * 10);
      case 'off':
        return false;
      default:
        return false;
    }
  };

  return (
    <div className={styles.vfdSpectrum}>
      {frequencyBars.map((bar) => (
        <div key={bar.frequencyId} className={styles.spectrumColumn}>
          {Array.from({ length: 12 }, (_, index) =>
            index < 3 ? (
              <div
                key={index}
                className={`${styles.spectrumBarRed} ${isEnabled && isBarActive(frequencies[bar.frequencyId], index) ? styles.active : ''}`}
              />
            ) : (
              <div
                key={index}
                className={`${styles.spectrumBar} ${isEnabled && isBarActive(frequencies[bar.frequencyId], index) ? styles.active : ''}`}
              />
            ),
          )}
          <div className={`${styles.spectrumBar} ${isEnabled && barsMode === 'bars' ? styles.active : ''}`}/>
          <p className={`${styles.barFrequencyDescription} ${isEnabled ? styles.active : ''}`}>
            {bar.label}
          </p>
        </div>
      ))}
    </div>
  );
};