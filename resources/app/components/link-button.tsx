import * as React from 'react';
import cx from 'clsx';
import { NavLink, RelativeRoutingType, To } from 'react-router-dom';
import { Button, ButtonProps } from '@mantine/core';

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
    <Button
      {...rest}
      renderRoot={({className, ...others}) => (
        <NavLink
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
    </Button>
  );
}