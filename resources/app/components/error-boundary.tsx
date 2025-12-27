import React, { Component, ErrorInfo, ReactNode } from 'react';
import { Text } from '@radix-ui/themes';
import { ExclamationTriangleIcon } from '@radix-ui/react-icons';

interface ErrorBoundaryProps {
  children: ReactNode;
  fallback?: ReactNode;
  onError?: (error: Error, errorInfo: ErrorInfo) => void;
  name?: string;
}

interface ErrorBoundaryState {
  hasError: boolean;
  error: Error | null;
}

/**
 * ErrorBoundary catches JavaScript errors anywhere in the child component tree,
 * logs those errors, and displays a fallback UI instead of the component tree that crashed.
 *
 * Usage:
 *   <ErrorBoundary name="Equalizer">
 *     <Equalizer />
 *   </ErrorBoundary>
 */
export class ErrorBoundary extends Component<ErrorBoundaryProps, ErrorBoundaryState> {
  constructor(props: ErrorBoundaryProps) {
    super(props);
    this.state = { hasError: false, error: null };
  }

  static getDerivedStateFromError(error: Error): ErrorBoundaryState {
    return { hasError: true, error };
  }

  componentDidCatch(error: Error, errorInfo: ErrorInfo) {
    // Log to console in development
    console.error(`ErrorBoundary caught an error in ${this.props.name || 'component'}:`, error, errorInfo);

    // Call custom error handler if provided
    this.props.onError?.(error, errorInfo);

    // You could also send to an error reporting service here
    // logErrorToService(error, errorInfo, this.props.name);
  }

  handleReset = () => {
    this.setState({ hasError: false, error: null });
  };

  render() {
    if (this.state.hasError) {
      // Use custom fallback if provided
      if (this.props.fallback) {
        return this.props.fallback;
      }

      // Default error UI
      return (
        <div style={{
          padding: '40px',
          textAlign: 'center',
          backgroundColor: 'var(--red-3)',
          borderRadius: '8px',
          margin: '20px',
        }}>
          <ExclamationTriangleIcon
            style={{
              width: '48px',
              height: '48px',
              color: 'var(--red-11)',
              marginBottom: '16px',
            }}
          />
          <Text
            size="6"
            weight="bold"
            style={{ color: 'var(--red-11)', marginBottom: '8px', display: 'block' }}
          >
            Something went wrong
          </Text>
          <div style={{ color: 'var(--red-11)', marginBottom: '24px', fontSize: '14px' }}>
            {this.props.name && (
              <>
                Error in <strong>{this.props.name}</strong>
                <br />
              </>
            )}
            {this.state.error?.message && (
              <code style={{ fontSize: '14px', opacity: 0.9 }}>
                {this.state.error.message}
              </code>
            )}
          </div>
          <button
            onClick={this.handleReset}
            style={{
              padding: '8px 16px',
              backgroundColor: 'var(--red-9)',
              color: 'var(--red-11)',
              border: '1px solid var(--red-11)',
              borderRadius: '4px',
              cursor: 'pointer',
              fontSize: '14px',
              fontWeight: 'bold',
            }}
            onMouseEnter={(e) => e.currentTarget.style.backgroundColor = 'var(--red-10)'}
            onMouseLeave={(e) => e.currentTarget.style.backgroundColor = 'var(--red-9)'}
          >
            Try Again
          </button>
        </div>
      );
    }

    return this.props.children;
  }
}

/**
 * HOC to wrap a component with an ErrorBoundary
 */
export function withErrorBoundary<P extends object>(
  Component: React.ComponentType<P>,
  errorBoundaryProps?: Omit<ErrorBoundaryProps, 'children'>
) {
  const WrappedComponent: React.FC<P> = (props) => (
    <ErrorBoundary {...errorBoundaryProps}>
      <Component {...props} />
    </ErrorBoundary>
  );

  WrappedComponent.displayName = `withErrorBoundary(${Component.displayName || Component.name})`;

  return WrappedComponent;
}

/**
 * Hook to throw an error that will be caught by the ErrorBoundary
 * Useful for handling errors in async operations or event handlers
 */
export function useErrorHandler() {
  return (error: Error) => {
    throw error;
  };
}
