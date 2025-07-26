import React, { useEffect, useMemo, createContext, useContext } from 'react';
import { trace, metrics } from '@opentelemetry/api';

interface OpenTelemetryContextType {
  tracer: any;
  meter: any;
  pageViewCounter: any;
  errorCounter: any;
  performanceHistogram: any;
}

const OpenTelemetryContext = createContext<OpenTelemetryContextType | null>(null);

interface OpenTelemetryProviderProps {
  children: React.ReactNode;
}

export const OpenTelemetryProvider: React.FC<OpenTelemetryProviderProps> = ({ children }) => {
  const tracer = useMemo(() => trace.getTracer('baander-web'), []);
  const meter = useMemo(() => metrics.getMeter('baander-web'), []);

  // Create metrics
  const pageViewCounter = useMemo(() =>
      meter.createCounter('page_views', {
        description: 'Number of page views',
      }),
    [meter]
  );

  const errorCounter = useMemo(() =>
      meter.createCounter('errors', {
        description: 'Number of errors',
      }),
    [meter]
  );

  const performanceHistogram = useMemo(() =>
      meter.createHistogram('performance_duration', {
        description: 'Performance timing measurements',
        unit: 'ms',
      }),
    [meter]
  );

  useEffect(() => {
    // Track initial page view
    pageViewCounter.add(1, {
      path: window.location.pathname,
      referrer: document.referrer || 'direct',
    });

    // Global error handling
    const handleError = (event: ErrorEvent) => {
      const span = tracer.startSpan('global_error');
      span.recordException(event.error);
      span.setStatus({ code: 2, message: event.error?.message || 'Unknown error' });
      span.end();

      errorCounter.add(1, {
        type: 'javascript_error',
        message: event.error?.message || 'Unknown error',
      });

      // Use console.error for logging since OpenTelemetry logs API doesn't exist
      console.error('OpenTelemetry captured error:', {
        message: event.error?.message,
        stack: event.error?.stack,
        filename: event.filename,
        lineno: event.lineno,
      });
    };

    const handleUnhandledRejection = (event: PromiseRejectionEvent) => {
      const span = tracer.startSpan('unhandled_promise_rejection');
      span.recordException(event.reason);
      span.setStatus({ code: 2, message: 'Unhandled promise rejection' });
      span.end();

      errorCounter.add(1, {
        type: 'unhandled_promise_rejection',
        reason: String(event.reason),
      });

      console.error('OpenTelemetry captured unhandled rejection:', {
        reason: event.reason,
      });
    };

    window.addEventListener('error', handleError);
    window.addEventListener('unhandledrejection', handleUnhandledRejection);

    return () => {
      window.removeEventListener('error', handleError);
      window.removeEventListener('unhandledrejection', handleUnhandledRejection);
    };
  }, [tracer, pageViewCounter, errorCounter]);

  const contextValue = useMemo(() => ({
    tracer,
    meter,
    pageViewCounter,
    errorCounter,
    performanceHistogram,
  }), [tracer, meter, pageViewCounter, errorCounter, performanceHistogram]);

  return (
    <OpenTelemetryContext.Provider value={contextValue}>
      {children}
    </OpenTelemetryContext.Provider>
  );
};

export const useOpenTelemetry = () => {
  const context = useContext(OpenTelemetryContext);
  if (!context) {
    throw new Error('useOpenTelemetry must be used within OpenTelemetryProvider');
  }
  return context;
};