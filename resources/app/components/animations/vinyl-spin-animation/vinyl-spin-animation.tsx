import { useLayoutEffect, createRef } from 'react';
import lazyLottie from '@/utils/lazy-lottie.ts';

interface VinylSpinAnimationProps extends React.HTMLProps<HTMLElement> {}
export function VinylSpinAnimation({...rest}: VinylSpinAnimationProps) {
  const ref = createRef<HTMLElement>();

  useLayoutEffect(() => {
    if (ref.current) {
      lazyLottie.loadAnimation({
        container: ref.current!,
        path: new URL(`./vinyl-spin-animation.json`, import.meta.url).href,
        autoplay: true,
        renderer: 'svg',
        loop: true,
      });
    }
  }, []);

  return (
    <div
      // @ts-ignore
      ref={ref}
      {...rest}
    />
  );
}