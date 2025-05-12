import { useState, forwardRef, useImperativeHandle } from 'react';
import { Toast as AppToast, ToastProps } from './toast.tsx';

interface ToastImperativeHandle {
  publish: () => void;
}

export const Toast = forwardRef<ToastImperativeHandle, ToastProps>((props, forwardedRef) => {
  const { children, ...toastProps } = props;
  const [count, setCount] = useState(0);

  useImperativeHandle(forwardedRef, () => ({
    publish: () => setCount((count) => count + 1),
  }));

  return (
    <>
      {Array.from({ length: count }).map((_, index) => (
        <AppToast key={index} {...toastProps}>
          {children}
        </AppToast>
      ))}
    </>
  );
});