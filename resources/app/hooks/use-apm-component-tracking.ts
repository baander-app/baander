// hooks/use-apm-component-tracking.ts
import { useEffect, useRef } from 'react';
import { reactApm } from '@/services/apm';

export function useApmComponentTracking(componentName: string) {
  const renderSpanRef = useRef<any>(null);

  useEffect(() => {
    // Track component mount
    const mountSpan = reactApm.trackComponentMount(componentName);

    return () => {
      // Track component unmount
      mountSpan?.end();
      reactApm.trackComponentUnmount(componentName);
    };
  }, [componentName]);

  const startRenderTracking = () => {
    renderSpanRef.current = reactApm.startComponentRender(componentName);
  };

  const endRenderTracking = () => {
    if (renderSpanRef.current) {
      renderSpanRef.current.end();
      renderSpanRef.current = null;
    }
  };

  const trackCustomSpan = (spanName: string, type: string = 'custom') => {
    return reactApm.trackComponentLifecycle(componentName, spanName, { type });
  };

  return {
    startRenderTracking,
    endRenderTracking,
    trackCustomSpan,
  };
}