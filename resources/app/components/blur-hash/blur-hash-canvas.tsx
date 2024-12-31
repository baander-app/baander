import { decodeBlurHash } from 'fast-blurhash';
import { CanvasHTMLAttributes, useEffect, useRef } from 'react';

export interface BlurHashCanvasProps extends CanvasHTMLAttributes<HTMLCanvasElement> {
  hash: string;
  height: number;
  width: number;
  punch?: number;
}

export function BlurHashCanvas({ hash, height = 128, width = 128, punch, ...rest }: BlurHashCanvasProps) {
  const ref = useRef<HTMLCanvasElement>(null);

  const draw = () => {
    if (ref.current) {
      const pixels = decodeBlurHash(hash, width, height, punch);
      const ctx = ref.current.getContext('2d');
      if (ctx) {
        const imageData = ctx.createImageData(width, height);
        imageData.data.set(pixels);
        ctx.putImageData(imageData, 0, 0);
      }
    }
  };

  useEffect(() => {
    if (hash) {
      draw();
    }
  }, [hash, height, width, punch]);

  return (
    <canvas
      {...rest}
      height={height}
      width={width}
      ref={ref}
    />
  );
}