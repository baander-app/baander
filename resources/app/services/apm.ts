
import { init as initApm } from '@elastic/apm-rum'

const apm = initApm({
  serviceName: 'baander-frontend',
  serverUrl: 'https://192.168.50.151:8200',
  serviceVersion: '1.0.0',
  environment: 'development',
})

// Custom React instrumentation utilities
export const reactApm = {
  // Track component render performance
  startComponentRender: (componentName: string) => {
    return apm.startSpan(`React Component: ${componentName}`, 'react.render');
  },

  // Track component lifecycle events
  trackComponentLifecycle: (componentName: string, lifecycle: string, metadata?: Record<string, any>) => {
    const span = apm.startSpan(`${componentName}.${lifecycle}`, 'react.lifecycle');
    if (metadata) {
      span?.addLabels(metadata);
    }
    return span;
  },

  // Track React errors
  captureReactError: (error: Error, componentName: string, errorInfo?: any) => {
    apm.captureError(error, {
      labels: {
        component: componentName,
        errorInfo: errorInfo?.componentStack || 'No component stack',
        props: errorInfo?.props || {},
        type: 'react-error',
      }
    });
  },

  // Track component mount/unmount
  trackComponentMount: (componentName: string) => {
    return apm.startSpan(`${componentName}.mount`, 'react.mount');
  },

  trackComponentUnmount: (componentName: string) => {
    return apm.startSpan(`${componentName}.unmount`, 'react.unmount');
  },

  // Track prop changes
  trackPropChanges: (componentName: string, changedProps: string[]) => {
    const span = apm.startSpan(`${componentName}.propsChanged`, 'react.update');
    span?.addLabels({
      changedProps: changedProps.join(', '),
      propCount: changedProps.length,
    });
    return span;
  },
};

export { apm }