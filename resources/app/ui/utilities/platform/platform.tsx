import { ReactNode, useMemo } from 'react';
import { getPlatform, Platform as PlatformType } from '@/app/utils/platform.ts';

export interface PlatformProps {
  platform: PlatformType;
  children: ReactNode;
}

export function Platform({ platform, children }: PlatformProps) {
  const currentPlatform = useMemo(() => getPlatform(), []);

  if (currentPlatform === platform) {
    return <>{children}</>
  }

  return null;
}
