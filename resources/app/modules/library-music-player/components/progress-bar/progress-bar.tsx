import { Slider } from 'radix-ui';
import styles from './progress-bar.module.css';

interface ProgressBarProps {
  duration: number;
  currentProgress: number;
  buffered: number;
  setProgress: (currentProgress: number) => void;
}

export function ProgressBar({ duration, currentProgress, setProgress }: ProgressBarProps) {

  // const progressBarWidth = isNaN(currentProgress / duration)
  //   ? 0
  //   : currentProgress / duration;
  // const bufferedWidth = isNaN(buffered / duration) ? 0 : buffered / duration;
  // // @ts-ignore
  // const progressStyles: ProgressCSSProps = {
  //   '--progress-width': progressBarWidth,
  //   '--buffered-width': bufferedWidth,
  // };

  return (
    <Slider.Root
      className={styles.Root}
      min={0}
      defaultValue={[currentProgress]}
      max={Number(duration)}
      onValueChange={(progress) => setProgress(progress[0])}
    >
      <Slider.Track className={styles.Track}>
        <Slider.Range className={styles.RangeProgress}/>
        {/*<Slider.Range className={styles.RangeProgress}/>*/}
      </Slider.Track>

      <Slider.Thumb className={styles.Thumb} aria-label="Song progress"/>
    </Slider.Root>
  );
}