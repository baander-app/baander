import { ComponentPropsWithoutRef } from 'react';
import { Flex, Text } from '@radix-ui/themes';
import { lazyImport } from '@/utils/lazy-import.ts';

const { BaanderLogo } = lazyImport(() => import('@/ui/branding/baander-logo/baander-logo.tsx'), 'BaanderLogo');

export interface BrandProps extends ComponentPropsWithoutRef<'div'> {
}

export function Brand({...rest}) {

  return (
    <Flex {...rest}>
      <Flex align="center" gap="2">
        <BaanderLogo />
        <Text weight="medium" size="3">{import.meta.env.VITE_APP_NAME}</Text>
      </Flex>
    </Flex>
  )
}