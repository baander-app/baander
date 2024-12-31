import { useLayoutEffect } from 'react';

export function useBlockBodyScroll() {
  useLayoutEffect(() => {
    const style = window.getComputedStyle(document.body).overflow;
    document.body.style.overflow = 'hidden';

    return () => {
      document.body.style.overflow = style;
    };
  });
}