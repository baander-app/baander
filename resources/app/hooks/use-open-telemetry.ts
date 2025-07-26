import { useCallback, useEffect, useRef } from 'react';
import { useOpenTelemetry } from '@/providers/open-telemetry-provider.tsx';

// Hook for tracking API calls
export const useTrackApiCall = () => {
  const { tracer } = useOpenTelemetry();

  return useCallback(async <T>(
    apiCall: () => Promise<T>,
    operationName: string,
    attributes: Record<string, any> = {}
  ): Promise<T> => {
    const span = tracer.startSpan(operationName);
    const startTime = performance.now();

    try {
      const result = await apiCall();
      span.setStatus({ code: 1 }); // SUCCESS
      return result;
    } catch (error) {
      span.recordException(error);
      span.setStatus({ code: 2, message: (error as Error).message });
      throw error;
    } finally {
      const duration = performance.now() - startTime;
      span.setAttributes({
        'operation.duration': duration,
        ...attributes,
      });
      span.end();
    }
  }, [tracer]);
};

// Hook for tracking component performance
export const useTrackComponentPerformance = (componentName: string) => {
  const { tracer, performanceHistogram } = useOpenTelemetry();
  const renderStart = useRef<number>(0);

  useEffect(() => {
    renderStart.current = performance.now();
  });

  useEffect(() => {
    const renderDuration = performance.now() - renderStart.current;

    const span = tracer.startSpan(`${componentName}_render`);
    span.setAttributes({
      'component.name': componentName,
      'render.duration': renderDuration,
    });
    span.end();

    performanceHistogram.record(renderDuration, {
      component: componentName,
      operation: 'render',
    });
  });
};

// Hook for tracking user actions
export const useTrackUserAction = () => {
  const { tracer, meter } = useOpenTelemetry();
  const actionCounter = meter.createCounter('user_actions', {
    description: 'User interactions',
  });

  return useCallback((actionName: string, attributes: Record<string, any> = {}) => {
    const span = tracer.startSpan(`user_action_${actionName}`);
    span.setAttributes({
      'user.action': actionName,
      'user.timestamp': Date.now(),
      ...attributes,
    });
    span.end();

    actionCounter.add(1, {
      action: actionName,
      ...attributes,
    });
  }, [tracer, actionCounter]);
};