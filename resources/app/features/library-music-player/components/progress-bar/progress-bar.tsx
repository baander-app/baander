import * as React from 'react';
import styles from './progress-bar.module.scss';
import { Slider } from '@mantine/core';
import { SliderProps } from '@mantine/core/lib/components/Slider/Slider/Slider';
import { useEffect, useState } from 'react';

interface ProgressCSSProps extends React.CSSProperties {
  '--progress-width': number;
  '--buffered-width': number;
}

interface ProgressBarProps
  extends SliderProps {
  duration: number;
  currentProgress: number;
  buffered: number;
  setProgress: (currentProgress: number) => void;
}

export function ProgressBar({duration, currentProgress, setProgress, buffered, ...rest}: ProgressBarProps) {
  const [progressValue, setProgressValue] = useState(0);

  const progressBarWidth = isNaN(currentProgress / duration)
    ? 0
    : currentProgress / duration;
  const bufferedWidth = isNaN(buffered / duration) ? 0 : buffered / duration;
  // @ts-ignore
  const progressStyles: ProgressCSSProps = {
    '--progress-width': progressBarWidth,
    '--buffered-width': bufferedWidth,
  };

  useEffect(() => {
    if (currentProgress) {
      setProgressValue(currentProgress);
    }
  }, [currentProgress]);

  const onChange = (value: number) => {
    setProgressValue(value);
    setProgress(value);
  }

  return (
    <div className={styles.container}>
      <Slider
        min={0}
        max={Number(duration)}
        size="sm"
        value={progressValue}
        onChange={onChange}
        {...rest}
      />
    </div>
  );
}