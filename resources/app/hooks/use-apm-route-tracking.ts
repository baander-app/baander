import { useEffect, useRef, useMemo } from 'react';
import { useLocation, useNavigationType, useParams, useMatch, matchPath } from 'react-router-dom';
import { apm } from '@/services/apm.ts';
import { publicRoutes } from '@/routes/public';
import { protectedRoutes } from '@/routes/protected';
import { useAppSelector } from '@/store/hooks.ts';
import { selectIsAuthenticated } from '@/store/users/auth-slice.ts';

export function useApmRouteTracking() {
  const location = useLocation();
  const navigationType = useNavigationType(); // 'POP', 'PUSH', or 'REPLACE'
  const params = useParams(); // Get route params
  const prevPathRef = useRef<string | null>(null);
  const isAuthenticated = useAppSelector(selectIsAuthenticated);

  // Get the appropriate routes based on authentication status
  const routes = isAuthenticated ? protectedRoutes : publicRoutes;

  // Function to find the matching route and extract its name
  const findMatchingRoute = useMemo(() => {
    // Get all routes from the router
    const getAllRoutes = () => {
      // Flatten the route configuration to make it easier to search
      const flattenRoutes = (routeArray: any[], parentPath = '', parentName = '') => {
        let result: { path: string; name: string }[] = [];

        routeArray.forEach(route => {
          const fullPath = parentPath ? 
            (route.path?.startsWith('/') ? route.path : `${parentPath}/${route.path || ''}`) : 
            route.path || '';

          // Skip catch-all routes
          if (fullPath === '*' || fullPath.endsWith('/*')) {
            return;
          }

          // Add this route
          if (fullPath) {
            // Extract a name from the path
            const pathSegments = fullPath.split('/').filter(Boolean);
            const lastSegment = pathSegments[pathSegments.length - 1] || 'home';
            const routeName = lastSegment.replace(/:/g, ''); // Remove parameter markers

            // Use component displayName or name if available
            // Try to get the name from the element's type (for React elements)
            let elementName = route.element?.type?.displayName || route.element?.type?.name;

            // If that doesn't work, try to get it from the element itself (for function/class components)
            if (!elementName && typeof route.element === 'function') {
              elementName = route.element.displayName || route.element.name;
            }

            // If the element is a React component with a name property, use that
            if (!elementName && route.element?.props?.name) {
              elementName = route.element.props.name;
            }

            // Create a more descriptive name by combining parent name with current name
            let descriptiveName = elementName || routeName;
            if (parentName && descriptiveName !== 'home') {
              descriptiveName = `${parentName}.${descriptiveName}`;
            }

            result.push({ 
              path: fullPath, 
              name: descriptiveName 
            });

            // Add child routes
            if (route.children) {
              // Pass the current route's name as the parent name for child routes
              const currentRouteName = elementName || routeName;
              result = [...result, ...flattenRoutes(route.children, fullPath, currentRouteName)];
            }
          }
        });

        return result;
      };

      return flattenRoutes(routes);
    };

    const allRoutes = getAllRoutes();

    // Find the most specific matching route
    let bestMatch = null;
    let bestMatchScore = -1;

    allRoutes.forEach(route => {
      const match = matchPath(route.path, location.pathname);

      if (match) {
        // Score the match based on specificity (number of segments that match)
        const score = match.pathname.split('/').filter(Boolean).length;

        if (score > bestMatchScore) {
          bestMatch = { ...route, params: match.params };
          bestMatchScore = score;
        }
      }
    });

    return bestMatch;
  }, [routes, location.pathname]);

  // Match against common route patterns
  const dashboardMatch = useMatch('/dashboard/*');
  const libraryMatch = useMatch('/library/:library/*');
  const playlistMatch = useMatch('/playlists/music/*');
  const userSettingsMatch = useMatch('/user/settings/*');
  const authMatch = useMatch('/auth/*');

  // Determine the route type based on matches
  const routeType = useMemo(() => {
    if (dashboardMatch) return 'dashboard';
    if (libraryMatch) return 'library';
    if (playlistMatch) return 'playlist';
    if (userSettingsMatch) return 'user-settings';
    if (authMatch) return 'auth';
    if (location.pathname === '/') return 'home';
    return 'other';
  }, [dashboardMatch, libraryMatch, playlistMatch, userSettingsMatch, authMatch, location.pathname]);

  // Extract query parameters
  const queryParams = useMemo(() => {
    const searchParams = new URLSearchParams(location.search);
    const params: Record<string, string> = {};
    searchParams.forEach((value, key) => {
      params[key] = value;
    });
    return params;
  }, [location.search]);

  useEffect(() => {
    // Get the previous path for transition tracking
    const prevPath = prevPathRef.current;

    // Get the route name from the matching route
    const routeName = findMatchingRoute?.['name'] || routeType;

    // Start a new transaction for the route
    const transaction = apm.getCurrentTransaction() ?? apm.startTransaction('Route: ' + routeName, 'route.change');
    const span = transaction?.startSpan(`Route: ${routeName}:${location.pathname}`, 'route.change');

    // Add detailed metadata about the route
    span?.addLabels({
      route: location.pathname,
      routeType,
      routeName,
      search: location.search,
      hash: location.hash,
      navigationType,
      prevPath: prevPath || 'initial',
      timestamp: new Date().toISOString(),
    });

    // Set custom context with more detailed information
    span?.addContext({
      page: {
        url: location.pathname + location.search + location.hash,
        route: location.pathname,
        routeType,
        routeName,
        title: document.title,
        referrer: document.referrer,
        queryParams: Object.keys(queryParams).length > 0 ? queryParams : undefined,
        routeParams: Object.keys(params).length > 0 ? params : undefined,
        matchedPath: findMatchingRoute,
      },
      navigation: {
        type: navigationType,
        from: prevPath || 'initial',
        to: location.pathname,
      },
      routeMatches: {
        dashboard: !!dashboardMatch,
        library: !!libraryMatch,
        playlist: !!playlistMatch,
        userSettings: !!userSettingsMatch,
        auth: !!authMatch,
      }
    });

    // Update the previous path ref for the next navigation
    prevPathRef.current = location.pathname;

    // For Elastic APM RUM, use captureError for logging (if needed)
    // or just rely on the transaction data

    // Create a span for page load metrics if this is the initial load
    if (!prevPath) {
      const pageLoadSpan = transaction?.startSpan('page-load-metrics', 'page.metrics');
      if (pageLoadSpan) {
        // Use modern Performance Timeline API to get page load metrics
        if (window.performance && 'getEntriesByType' in window.performance) {
          const navEntries = window.performance.getEntriesByType('navigation');
          if (navEntries.length > 0) {
            const navTiming = navEntries[0] as PerformanceNavigationTiming;

            pageLoadSpan.addLabels({
              pageLoadTime: navTiming.loadEventEnd - navTiming.startTime,
              domLoadTime: navTiming.domComplete - navTiming.domContentLoadedEventStart,
              redirectTime: navTiming.redirectEnd - navTiming.redirectStart,
              dnsLookupTime: navTiming.domainLookupEnd - navTiming.domainLookupStart,
              tcpConnectTime: navTiming.connectEnd - navTiming.connectStart,
              serverResponseTime: navTiming.responseEnd - navTiming.requestStart,
              domParseTime: navTiming.domInteractive - navTiming.responseEnd,
            });
          }
        }
        pageLoadSpan.end();
      }
    }

    return () => {
      transaction?.end();
    };
  }, [
    location.pathname, 
    location.search, 
    location.hash, 
    navigationType, 
    routeType, 
    params, 
    queryParams, 
    dashboardMatch, 
    libraryMatch, 
    playlistMatch, 
    userSettingsMatch, 
    authMatch,
    findMatchingRoute
  ]);
}
