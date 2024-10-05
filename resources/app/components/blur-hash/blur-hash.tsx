import { CSSProperties, HTMLAttributes, useEffect } from 'react';
import { BlurHashCanvas } from '@/components/blur-hash/blur-hash-canvas.tsx';

const canvasStyle: CSSProperties = {
  position: 'absolute',
  top: 0,
  bottom: 0,
  left: 0,
  right: 0,
}

export interface BlurHashProps extends HTMLAttributes<HTMLDivElement> {
  hash: string;
  resolutionY: number;
  resolutionX: number;
  height?: number;
  width?: number;
  punch?: number;
  style?: CSSProperties;
}
export function BlurHash({hash, height, width, punch, resolutionX, resolutionY, style, ...rest}: BlurHashProps) {
  useEffect(() => {
    if (resolutionX && resolutionX <= 0) {
      throw new Error('resolutionX must be greater than 0');
    }
    if (resolutionY && resolutionY <= 0) {
      throw new Error('resolutionY must be greater than 0');
    }
  }, [resolutionX, resolutionY]);

  return (
    <div
      {...rest}
      style={{ display: 'inline-block', height, width, ...style, position: 'relative' }}
    >
      <BlurHashCanvas
        hash={hash}
        height={resolutionY}
        width={resolutionX}
        punch={punch}
        style={canvasStyle}
        />
    </div>
  )
}