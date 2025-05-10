import { PropsWithChildren } from 'react';
import { Box, ScrollArea } from '@radix-ui/themes';
import { RemoveScroll } from 'react-remove-scroll';

export function SideNav({ children }: PropsWithChildren) {
  return (
    <Box
      display={{ initial: 'none', md: 'block' }}
      style={{ width: 250, flexShrink: 0 }}
      className={RemoveScroll.classNames.zeroRight}
    >
      <ScrollArea>{children}</ScrollArea>
    </Box>
  );
}