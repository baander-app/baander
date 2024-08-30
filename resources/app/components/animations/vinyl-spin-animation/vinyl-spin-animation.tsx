import Lottie, { LottieProps } from 'react-lottie-player';
import lottieJson from './vinyl-spin-animation.json';

export function VinylSpinAnimation({...rest}: LottieProps) {
  return (
    <Lottie
      animationData={lottieJson}
      {...rest}
    />
  )
}