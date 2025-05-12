import { Theme } from '@radix-ui/themes';
import { Toast, Tooltip } from 'radix-ui';
import { ReactNode } from 'react';

export const RadixProvider = ({ children }: { children: ReactNode }) => {
  return (
    <Theme accentColor="red" radius="large">
      <Toast.Provider swipeDirection="right">
        <Tooltip.Provider>
          {children}
        </Tooltip.Provider>
      </Toast.Provider>
    </Theme>
  );
};