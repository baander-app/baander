import { ReactNode } from 'react';
import { Toast as ToastPrimitive } from 'radix-ui';
import { ToastProps as ToastPrimitiveProps } from '@radix-ui/react-toast';

export interface ToastPropsBase extends ToastPrimitiveProps {
  actionAltText?: string;
  children?: ReactNode;
}

export interface ToastPropsWithAction extends ToastPrimitiveProps {
  actionAltText: string;
  children: ReactNode;
}

export type ToastProps = ToastPropsBase | ToastPropsWithAction;

export function Toast({ title, content, children, actionAltText, ...rest }: ToastProps) {
  if (children && !actionAltText) {
    console.error('Toast: `actionAltText` is required when `children` is provided. We will make a false value so the ui doesnt break.');

    actionAltText = `${title} action button`
  }

  return (
    <ToastPrimitive.Root {...rest}>
      {title && <ToastPrimitive.Title>{title}</ToastPrimitive.Title>}
      <ToastPrimitive.Description>{content}</ToastPrimitive.Description>
      {children && actionAltText && (
        <ToastPrimitive.Action altText={actionAltText} asChild>{children}</ToastPrimitive.Action>
      )}
      <ToastPrimitive.Close aria-label="Close">
        <span aria-hidden>×</span>
      </ToastPrimitive.Close>
    </ToastPrimitive.Root>
  );
}