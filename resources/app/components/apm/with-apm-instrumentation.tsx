// components/apm/with-apm-instrumentation.tsx
import React, { ComponentType, useEffect, useRef, useState } from 'react';
import { reactApm } from '@/services/apm';

interface ApmInstrumentationOptions {
  trackRenders?: boolean;
  trackMounts?: boolean;
  trackPropChanges?: boolean;
  trackErrors?: boolean;
}

export function withApmInstrumentation<P extends object>(
  WrappedComponent: ComponentType<P>,
  componentName?: string,
  options: ApmInstrumentationOptions = {
    trackRenders: true,
    trackMounts: true,
    trackPropChanges: true,
    trackErrors: true,
  }
) {
  const displayName = componentName || WrappedComponent.displayName || WrappedComponent.name || 'Component';

  const InstrumentedComponent: React.FC<P> = (props) => {
    const renderSpanRef = useRef<any>(null);
    const mountSpanRef = useRef<any>(null);
    const prevPropsRef = useRef<P>(props);
    const [hasError, setHasError] = useState(false);

    // Track component mount
    useEffect(() => {
      if (options.trackMounts) {
        mountSpanRef.current = reactApm.trackComponentMount(displayName);
      }

      return () => {
        // Track component unmount
        if (options.trackMounts && mountSpanRef.current) {
          mountSpanRef.current.end();
          reactApm.trackComponentUnmount(displayName);
        }
      };
    }, []);

    // Track prop changes
    useEffect(() => {
      if (options.trackPropChanges && prevPropsRef.current) {
        const changedProps = Object.keys(props).filter(
          key => (props as any)[key] !== (prevPropsRef.current as any)[key]
        );

        if (changedProps.length > 0) {
          const span = reactApm.trackPropChanges(displayName, changedProps);
          span?.end();
        }
      }

      prevPropsRef.current = props;
    });

    // Track renders
    useEffect(() => {
      if (options.trackRenders) {
        renderSpanRef.current = reactApm.startComponentRender(displayName);

        // End render span after a microtask to capture the full render
        Promise.resolve().then(() => {
          if (renderSpanRef.current) {
            renderSpanRef.current.end();
          }
        });
      }
    });

    if (hasError) {
      return <div>Something went wrong in {displayName}</div>;
    }

    try {
      return <WrappedComponent {...props} />;
    } catch (error) {
      if (options.trackErrors) {
        reactApm.captureReactError(
          error as Error,
          displayName,
          { props }
        );
      }
      setHasError(true);
      throw error;
    }
  };

  InstrumentedComponent.displayName = `withApmInstrumentation(${displayName})`;

  return InstrumentedComponent;
}