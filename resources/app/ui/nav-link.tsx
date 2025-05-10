import * as React from 'react';
import { ReactNode } from 'react';
import { Link as RouterNavLink, RelativeRoutingType, To } from 'react-router-dom';
import { Link as RadixLink } from '@radix-ui/themes';
import { clsx } from 'clsx';

interface NavLinkProps {
  to: To;
  children?: React.ReactNode;
  reloadDocument?: boolean;
  replace?: boolean;
  state?: any;
  preventScrollReset?: boolean;
  relative?: RelativeRoutingType;
  className?: string;
  activeClassName?: string;
  label?: string;
  leftSection?: ReactNode;
  rightSection?: ReactNode;
}

export function NavLink({
                          children,
                          to,
                          reloadDocument,
                          replace,
                          state,
                          preventScrollReset,
                          relative,
                          className,
                          activeClassName = 'active-class',
                          label,
                          leftSection,
                          rightSection,
                          ...rest
                        }: NavLinkProps) {
  return (
    <div>
      {leftSection && <div>{leftSection}</div>}
      <RadixLink asChild>
        <RouterNavLink
          to={to}
          reloadDocument={reloadDocument}
          replace={replace}
          state={state}
          preventScrollReset={preventScrollReset}
          relative={relative}
          className={clsx(
            className,
            ({ isActive }: { isActive: boolean }) =>
              clsx(className, {
                [activeClassName]: isActive,
              }))}
          {...rest}
        >
          {label && <span className="sr-only">{label}</span>}
          {children}
        </RouterNavLink>
      </RadixLink>
      {rightSection && <div>{rightSection}</div>}
    </div>
  );
}