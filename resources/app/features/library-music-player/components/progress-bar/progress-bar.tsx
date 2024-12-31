import Slider from 'rc-slider';
import { SliderProps } from 'rc-slider/lib/Slider';
import 'rc-slider/assets/index.css';
import { useMantineTheme } from '@mantine/core';

interface ProgressBarProps
  extends SliderProps {
  duration: number;
  currentProgress: number;
  buffered: number;
  setProgress: (currentProgress: number) => void;
}

export function ProgressBar({duration, currentProgress, setProgress, buffered, ...rest}: ProgressBarProps) {
  const theme = useMantineTheme();

  // const progressBarWidth = isNaN(currentProgress / duration)
  //   ? 0
  //   : currentProgress / duration;
  // const bufferedWidth = isNaN(buffered / duration) ? 0 : buffered / duration;
  // // @ts-ignore
  // const progressStyles: ProgressCSSProps = {
  //   '--progress-width': progressBarWidth,
  //   '--buffered-width': bufferedWidth,
  // };

  const onChange = (value: number | number[]) => {
    setProgress(Number(value));
  }

  return (
    <Slider
      min={0}
      max={Number(duration)}
      value={currentProgress}
      onChange={value => onChange(value)}
      styles={{
        handle: {
          borderColor: theme.colors.gray[5],
          borderWidth: '1px',
          height: '12px',
          width: '12px',
          marginTop: '-4px',
        },
        track: {
          backgroundColor: theme.colors.gray[4],
        }
      }}
      {...rest}
    />
  );
}