import React, { useCallback, useContext, useEffect, useMemo } from 'react';
import { useNavigate } from 'react-router-dom';
import { noop } from '@/app/utils/noop.ts';
import { useDeepLink } from '@/app/hooks/use-deep-link.ts';
import { DeepLinkRoute, parseDeepLinkUrl } from '@/app/utils/deep-link-parser.ts';

interface DeepLinkContextType {
  handleDeepLink: (route: DeepLinkRoute) => void;
  pendingUrl: string | null;
  hasPendingUrl: boolean;
}

export const DeepLinkContext = React.createContext<DeepLinkContextType>({
  handleDeepLink: () => noop(),
  pendingUrl: null,
  hasPendingUrl: false,
});
DeepLinkContext.displayName = 'DeepLinkContext';

export function DeepLinkProvider({ children }: { children: React.ReactNode }) {
  const navigate = useNavigate();
  const { pendingUrl, consumeUrl, hasPendingUrl } = useDeepLink();

  const handleDeepLink = useCallback((route: DeepLinkRoute) => {
    switch (route.type) {
      case 'song':
        if (route.id) {
          if (route.action === 'play') {
            navigate(`/song/${route.id}?autoplay=true`);
          } else {
            navigate(`/song/${route.id}`);
          }
        }
        break;

      case 'album':
        if (route.id) {
          if (route.action === 'play') {
            navigate(`/album/${route.id}?autoplay=true`);
          } else {
            navigate(`/album/${route.id}`);
          }
        }
        break;

      case 'playlist':
        if (route.id) {
          if (route.action === 'play') {
            navigate(`/playlist/${route.id}?autoplay=true`);
          } else {
            navigate(`/playlist/${route.id}`);
          }
        }
        break;

      case 'artist':
        if (route.id) {
          navigate(`/artist/${route.id}`);
        }
        break;

      default:
        console.log('Unknown deep link route:', route);
    }
  }, [navigate]);

  const contextValue = useMemo(() => ({
    handleDeepLink,
    pendingUrl,
    hasPendingUrl,
  }), [handleDeepLink, pendingUrl, hasPendingUrl]);

  useEffect(() => {
    if (hasPendingUrl) {
      const url = consumeUrl();
      if (url) {
        const route = parseDeepLinkUrl(url);
        if (route) {
          handleDeepLink(route);
        }
      }
    }
  }, [hasPendingUrl, consumeUrl, handleDeepLink]);

  return (
    <DeepLinkContext.Provider value={contextValue}>
      {children}
    </DeepLinkContext.Provider>
  );
}

export function useDeepLinkHandler() {
  return useContext(DeepLinkContext);
}
