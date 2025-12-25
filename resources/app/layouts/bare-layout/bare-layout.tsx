import { ReactNode } from 'react';
import { Flex } from '@radix-ui/themes';
import { lazyImport } from '@/app/utils/lazy-import.ts';

const { Brand } = lazyImport(() => import('@/app/ui/brand/Brand.tsx'), 'Brand');

export function BareLayout(props: { children?: ReactNode }) {
  return (
    <Flex height="100vh" direction="column" overflowY="auto">
      <Flex flexBasis="50px" pl="8px">
        <Brand />
      </Flex>

      <Flex style={{ flex: 1 }}>
        {props.children}
      </Flex>
    </Flex>
  );
}
