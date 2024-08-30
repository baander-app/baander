import * as React from 'react';
import cx from 'clsx';
import { NavLink as RouterNavLink, RelativeRoutingType, To } from 'react-router-dom';
import { NavLink as MantineNavLink, NavLinkProps as MantineNavLinkProps } from '@mantine/core';

interface NavLinkProps extends MantineNavLinkProps  {
  to: To;
  children?: React.ReactNode;
  reloadDocument?: boolean;
  replace?: boolean;
  state?: any;
  preventScrollReset?: boolean;
  relative?: RelativeRoutingType;
}

export function NavLink({children, to, reloadDocument, replace, state, preventScrollReset, relative, ...rest}: NavLinkProps) {
  return (
    <MantineNavLink
      {...rest}
      renderRoot={({className, ...others}) => (
        <RouterNavLink
          to={to}
          reloadDocument={reloadDocument}
          replace={replace}
          state={state}
          preventScrollReset={preventScrollReset}
          relative={relative}
          className={({isActive}) =>
            cx(className, {'active-class': isActive})
          }
          {...others}
        />
      )}
    >
      {children}
    </MantineNavLink>
  );
}