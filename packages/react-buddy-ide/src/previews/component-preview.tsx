import React, { ReactElement, useCallback } from 'react';
import { RoutePreview } from '../routing/route-preview';
import { ComponentPreviewProps, PropsEditInfo, PropsModifier, ToolsPropsModifier } from '../types';
import { ReactBuddyErrorBoundary } from '../react-buddy-error-boundary/react-buddy-error-boundary';

interface Props {
  path: string;
  children: ReactElement<ComponentPreviewProps>;
  propsEditInfo?: PropsEditInfo;
  setToolsPropsToEdit?: (toolsPropsModifier: ToolsPropsModifier) => void;
  exact?: boolean;
}

export const ComponentPreview: React.FC<Props> = ({
                                                    path,
                                                    children,
                                                    setToolsPropsToEdit,
                                                    exact = true,
                                                    propsEditInfo,
                                                  }: Props) => {
  const setPropsToEdit = useCallback((propsToEdit: PropsModifier) => {
    setToolsPropsToEdit!({
      ...propsToEdit,
      initialProps: children.props,
      propsEditInfo,
    });
  }, []);

  return (
    <RoutePreview path={path} exact={exact} setPropsToEdit={setPropsToEdit!}>
      <ReactBuddyErrorBoundary>{children}</ReactBuddyErrorBoundary>
    </RoutePreview>
  );
};
