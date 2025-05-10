import { Slider } from "radix-ui";
import styles from './progress-bar.module.css';
import { ChangeEvent } from 'react';


interface ProgressBarProps {
  duration: number;
  currentProgress: number;
  buffered: number;
  setProgress: (currentProgress: number) => void;
}

export function ProgressBar({duration, currentProgress, setProgress, buffered}: ProgressBarProps) {

  // const progressBarWidth = isNaN(currentProgress / duration)
  //   ? 0
  //   : currentProgress / duration;
  // const bufferedWidth = isNaN(buffered / duration) ? 0 : buffered / duration;
  // // @ts-ignore
  // const progressStyles: ProgressCSSProps = {
  //   '--progress-width': progressBarWidth,
  //   '--buffered-width': bufferedWidth,
  // };

  const handleChange = (e: ChangeEvent<HTMLInputElement>) => {
    e.preventDefault();

    setProgress(Number(e.target.value));
  }

  return (
    <Slider.Root
      className={styles.Root}
      min={0}
      value={[buffered, currentProgress]}
      max={Number(duration)}
      onChange={handleChange}
    >
      <Slider.Track className={styles.Track}>
        <Slider.Range className={styles.RangeBuffer} />
        <Slider.Range className={styles.RangeProgress} />
      </Slider.Track>

      <Slider.Thumb className={styles.Thumb} aria-label="Song progress" />
    </Slider.Root>
  );
}