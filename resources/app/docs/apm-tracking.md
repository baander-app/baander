# APM Tracking Implementation

This document describes the APM (Application Performance Monitoring) implementation in the application, which uses the Elastic APM RUM (Real User Monitoring) library.

## Overview

The APM implementation tracks various aspects of the application:

1. **Route Changes** - Tracks when users navigate between routes
2. **API Calls** - Tracks API requests and responses
3. **User Interactions** - Tracks user actions like button clicks and form submissions
4. **Component Performance** - Tracks React component rendering and lifecycle events
5. **Errors** - Captures and reports errors

## Implementation Details

### APM Service

The main APM service is defined in `resources/app/services/apm.ts`. It initializes the Elastic APM RUM client and provides utility functions for tracking React components.

```typescript
import { init as initApm } from '@elastic/apm-rum'

const apm = initApm({
  serviceName: 'baander-frontend',
  serverUrl: 'https://192.168.50.151:8200',
  serviceVersion: '1.0.0',
  environment: 'development',
})

// Custom React instrumentation utilities
export const reactApm = {
  // ... utility functions for tracking React components
};

export { apm }
```

### Route Tracking with React Router

Route changes are tracked using the `useApmRouteTracking` hook in `resources/app/hooks/use-apm-route-tracking.ts`. This hook creates a transaction for each route change and tracks detailed navigation information using React Router hooks.

The hook captures:
- Route path, search parameters, and hash
- Navigation type (PUSH, POP, REPLACE)
- Previous path for transition tracking
- Route parameters from dynamic routes
- Query parameters from the URL
- Route type based on pattern matching
- Page load metrics for initial loads

```typescript
// In App.tsx
import { useApmRouteTracking } from '@/hooks/use-apm-route-tracking';

function AppWithRouteTracking() {
  useApmRouteTracking();

  return (
    <ApmErrorBoundary>
      <AppRoutes />
    </ApmErrorBoundary>
  );
}
```

The hook uses several React Router hooks for comprehensive tracking:
- `useLocation` - Gets the current location object
- `useNavigationType` - Gets the navigation type (PUSH, POP, REPLACE)
- `useParams` - Gets route parameters from dynamic routes
- `useMatch` - Matches the current URL against route patterns

Each route change creates a transaction with detailed metadata:

```typescript
// Transaction name format
`Route: ${routeType}:${location.pathname}`

// Transaction labels
{
  route: location.pathname,
  routeType, // 'dashboard', 'library', 'playlist', etc.
  search: location.search,
  hash: location.hash,
  navigationType, // 'PUSH', 'POP', or 'REPLACE'
  prevPath: prevPath || 'initial',
  timestamp: new Date().toISOString(),
}

// Custom context
{
  page: {
    url: location.pathname + location.search + location.hash,
    route: location.pathname,
    routeType,
    title: document.title,
    referrer: document.referrer,
    queryParams: {...},
    routeParams: {...},
  },
  navigation: {
    type: navigationType,
    from: prevPath || 'initial',
    to: location.pathname,
  },
  routeMatches: {
    dashboard: boolean,
    library: boolean,
    playlist: boolean,
    userSettings: boolean,
    auth: boolean,
  }
}
```

### API Call Tracking

API calls are tracked using an interceptor in `resources/app/api-client-ext/interceptors/apm-transaction.interceptor.ts`. This interceptor creates a transaction for each API call and tracks request and response information.

```typescript
// In api-client-ext/interceptors/index.ts
import { apmTransactionInterceptor } from '@/api-client-ext/interceptors/apm-transaction.interceptor.ts';

export function applyInterceptors() {
  // ... other interceptors
  apmTransactionInterceptor();
}
```

### User Interaction Tracking

User interactions are tracked using the `apmUserInteractions` utility in `resources/app/services/apm-user-interactions.ts`. This utility provides functions for tracking various user actions.

```typescript
// In a component
import { useApmUserInteractions } from '@/services/apm-user-interactions';

function MyComponent() {
  const { trackButtonClick } = useApmUserInteractions();

  return (
    <Button onClick={() => trackButtonClick('Submit', { formId: 'login-form' })}>
      Submit
    </Button>
  );
}
```

### Component Performance Tracking

Component performance is tracked using the `withApmInstrumentation` HOC in `resources/app/components/apm/with-apm-instrumentation.tsx`. This HOC tracks component renders, mounts, prop changes, and errors.

```typescript
// In a component file
import { withApmInstrumentation } from '@/components/apm/with-apm-instrumentation';

function MyComponent(props) {
  // ... component implementation
}

export default withApmInstrumentation(MyComponent, 'MyComponent');
```

### Error Tracking

Errors are tracked using the `ApmErrorBoundary` component in `resources/app/components/apm/apm-error-boundary.tsx`. This component catches errors in the component tree and reports them to APM.

```typescript
// In a component
import { ApmErrorBoundary } from '@/components/apm/apm-error-boundary';

function MyComponent() {
  return (
    <ApmErrorBoundary>
      {/* Component content */}
    </ApmErrorBoundary>
  );
}
```

## Usage Examples

### Tracking Route Changes

The route tracking is automatically applied in the `App.tsx` file using the `useApmRouteTracking` hook.

### Tracking API Calls

API calls are automatically tracked by the `apmTransactionInterceptor`, which is applied in the `applyInterceptors` function.

### Tracking User Interactions

```typescript
import { useApmUserInteractions } from '@/services/apm-user-interactions';

function MyComponent() {
  const { 
    trackButtonClick, 
    trackFormSubmission, 
    trackSearch, 
    trackNavigation 
  } = useApmUserInteractions();

  return (
    <div>
      <Button onClick={() => trackButtonClick('Save', { itemId: '123' })}>
        Save
      </Button>

      <form onSubmit={(e) => {
        e.preventDefault();
        trackFormSubmission('login-form', { username: 'user123' });
        // Form submission logic
      }}>
        {/* Form fields */}
        <Button type="submit">Login</Button>
      </form>

      <SearchBox onSearch={(term) => {
        trackSearch(term);
        // Search logic
      }} />

      <NavLink 
        to="/dashboard" 
        onClick={() => trackNavigation('/dashboard', { from: '/home' })}
      >
        Dashboard
      </NavLink>
    </div>
  );
}
```

### Tracking Component Performance

```typescript
import { withApmInstrumentation } from '@/components/apm/with-apm-instrumentation';

function ExpensiveComponent(props) {
  // ... component implementation with expensive operations
}

// Track all renders, mounts, and prop changes
export default withApmInstrumentation(
  ExpensiveComponent, 
  'ExpensiveComponent', 
  { 
    trackRenders: true,
    trackMounts: true,
    trackPropChanges: true,
    trackErrors: true
  }
);
```

### Error Handling

```typescript
import { ApmErrorBoundary } from '@/components/apm/apm-error-boundary';

function MyComponent() {
  return (
    <ApmErrorBoundary 
      fallback={<div>Something went wrong</div>}
      onError={(error, errorInfo) => {
        // Custom error handling
        console.error('Caught an error:', error, errorInfo);
      }}
    >
      {/* Component content that might throw errors */}
    </ApmErrorBoundary>
  );
}
```

## Conclusion

This APM implementation provides comprehensive tracking of the application's performance and user interactions. It helps identify performance bottlenecks, track user behavior, and catch errors early.
