import { useCallback, useState, useEffect, useRef } from 'react';

type DisclosureCallbacks = {
  onOpen?: () => void;
  onClose?: () => void;
};

export function useDisclosure(
  initialState = false,
  callbacks?: DisclosureCallbacks
): readonly [
  boolean,
  {
    open: () => void;
    close: () => void;
    toggle: () => void;
  }
] {
  const callbacksRef = useRef(callbacks);
  useEffect(() => {
    callbacksRef.current = callbacks;
  });

  const [isOpen, setIsOpen] = useState(initialState);

  useEffect(() => {
    setIsOpen(initialState);
  }, [initialState]);

  const open = useCallback(() => {
    setIsOpen((prev) => {
      if (!prev) {
        callbacksRef.current?.onOpen?.();
        return true;
      }
      return prev;
    });
  }, []);

  const close = useCallback(() => {
    setIsOpen((prev) => {
      if (prev) {
        callbacksRef.current?.onClose?.();
        return false;
      }
      return prev;
    });
  }, []);

  const toggle = useCallback(() => {
    setIsOpen((prev) => {
      const newState = !prev;
      if (newState) {
        callbacksRef.current?.onOpen?.();
      } else {
        callbacksRef.current?.onClose?.();
      }
      return newState;
    });
  }, []);

  return [isOpen, { open, close, toggle }] as const;
}