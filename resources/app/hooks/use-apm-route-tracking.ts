import { useEffect } from 'react';
import { useLocation } from 'react-router-dom';
import { apm } from '@/services/apm.ts';

export function useApmRouteTracking() {
  const location = useLocation();

  useEffect(() => {
    // Start a new transaction for the route
    const transaction = apm.startTransaction(`${location.pathname}`, 'route-change');

    // Add metadata about the route
    transaction?.addLabels({
      route: location.pathname,
      search: location.search,
      hash: location.hash,
    });

    // Set custom context
    apm.setCustomContext({
      page: {
        url: location.pathname + location.search + location.hash,
        route: location.pathname,
      }
    });

    // For Elastic APM RUM, use captureError for logging (if needed)
    // or just rely on the transaction data

    return () => {
      transaction?.end();
    };
  }, [location.pathname, location.search, location.hash]);
}