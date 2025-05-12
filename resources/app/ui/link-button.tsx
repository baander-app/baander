import * as React from 'react';
import { NavLink, RelativeRoutingType, To } from 'react-router-dom';
import { Button, ButtonProps } from '@radix-ui/themes';

interface LinkButtonProps extends ButtonProps  {
  children: React.ReactNode;
  to: To;
  reloadDocument?: boolean;
  replace?: boolean;
  state?: any;
  preventScrollReset?: boolean;
  relative?: RelativeRoutingType;
}

export function LinkButton({children, to, reloadDocument, replace, state, preventScrollReset, relative, ...rest}: LinkButtonProps) {
  return (
    <NavLink
      to={to}
      reloadDocument={reloadDocument}
      replace={replace}
      state={state}
      preventScrollReset={preventScrollReset}
      relative={relative}
      // className={({isActive}) =>
      //   clsx(className, {'active-class': isActive})
      // }
    >
    <Button
      {...rest}
    >
      {children}
    </Button>
    </NavLink>
  );
}