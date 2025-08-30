import React, { useEffect, useMemo, createContext, useContext } from 'react';
import { trace, metrics, createNoopMeter } from '@opentelemetry/api';
import { Counter, Histogram } from '@opentelemetry/api/build/src/metrics/Metric';
import { Tracer } from '@opentelemetry/api/build/src/trace/tracer';
import { Meter } from '@opentelemetry/api/build/src/metrics/Meter';
import { Span } from '@opentelemetry/api/build/src/trace/span';
import { SpanOptions } from '@opentelemetry/api/build/src/trace/SpanOptions';
import { Context } from '@opentelemetry/api/build/src/context/types';

interface OpenTelemetryContextType {
  tracer: Tracer;
  meter: Meter;
  pageViewCounter: Counter<{description: string}>;
  errorCounter: Counter<{description: string}>;
  performanceHistogram: Histogram<{description: string}>;
}

const OpenTelemetryContext = createContext<OpenTelemetryContextType | null>(null);

interface OpenTelemetryProviderProps {
  children: React.ReactNode;
}

const createNoopSpan = (): Span => ({
  spanContext: () => ({ traceId: '', spanId: '', traceFlags: 0 }),
  setAttribute: () => createNoopSpan(),
  setAttributes: () => createNoopSpan(),
  addEvent: () => createNoopSpan(),
  addLink: () => createNoopSpan(),
  addLinks: () => createNoopSpan(),
  setStatus: () => createNoopSpan(),
  updateName: () => createNoopSpan(),
  end: () => {},
  isRecording: () => false,
  recordException: () => {},
});

const createNoopTracer = (): Tracer => ({
  startSpan: (): Span => createNoopSpan(),
  startActiveSpan: <F extends (span: Span) => unknown>(
    optionsOrFn: F | SpanOptions,
    contextOrFn?: F | Context,
    fn?: F
  ): ReturnType<F> => {
    const span = createNoopSpan();
    // Handle different overload signatures
    if (typeof optionsOrFn === 'function') {
      return optionsOrFn(span) as ReturnType<F>;
    }
    if (typeof contextOrFn === 'function') {
      return contextOrFn(span) as ReturnType<F>;
    }
    if (typeof fn === 'function') {
      return fn(span) as ReturnType<F>;
    }
    return undefined as any;
  }
});

export const OpenTelemetryProvider: React.FC<OpenTelemetryProviderProps> = ({ children }) => {
  const isTracingEnabled = window.BaanderAppConfig?.tracing?.enabled ?? false;

  const tracer = useMemo(() => {
    if (!isTracingEnabled) {
      return createNoopTracer();
    }
    return trace.getTracer('baander-web');
  }, [isTracingEnabled]);

  const meter = useMemo(() => {
    if (!isTracingEnabled) {
      return createNoopMeter();
    }
    return metrics.getMeter('baander-web');
  }, [isTracingEnabled]);

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
    // Only set up tracking when tracing is enabled
    if (!isTracingEnabled) {
      return;
    }

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
  }, [tracer, pageViewCounter, errorCounter, isTracingEnabled]);

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
