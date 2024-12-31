import React, { useLayoutEffect, createRef } from 'react';
import lazyLottie from '@/utils/lazy-lottie';

interface VinylSpinAnimationProps extends React.HTMLProps<HTMLElement> {}
export function VinylSpinAnimation({ ...rest }: VinylSpinAnimationProps) {
  const ref = createRef<HTMLElement>();

  useLayoutEffect(() => {
    if (ref.current) {
      const animation = lazyLottie.loadAnimation({
        container: ref.current,
        path: new URL('./vinyl-spin-animation.json', import.meta.url).href,
      });

      return () => {
        animation.destroy();
      };
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