import React from 'react';
import { useInitial } from './useInitial.ts';

const ComponentPreviews = React.lazy(() => import('./previews.tsx'));

export {
  ComponentPreviews,
  useInitial,
};