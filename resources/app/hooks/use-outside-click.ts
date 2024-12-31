import { useEffect, useLayoutEffect, useRef } from 'react';

export function useOutsideClick<T extends Element>(callback: (event: Event) => void) {
  const ref = useRef<T>(null);
  const referenceCallback = useRef(callback);

  useLayoutEffect(() => {
    referenceCallback.current = callback;
  });


  useEffect(() => {
    const handler = (event: Event) => {
      const element = ref.current;
      if (element) {
        console.log('element', element);
      }

      if (element && !element.contains(event.target as Node)) {
        console.log('clicked outside');
        referenceCallback.current(event);
      }
    };

    document.addEventListener('click', handler);
    document.addEventListener('touchstart', handler);

    return () => {
      document.removeEventListener('click', handler);
      document.removeEventListener('touchstart', handler);
    }
  }, []);

  return ref;
}
