import { useEffect, useState } from 'react';
import { isElectron } from '@/app/utils/platform.ts';

export function useDeepLink() {
  const [pendingUrl, setPendingUrl] = useState<string | null>(null);

  useEffect(() => {
    // Check for any pending deep link URL when the component mounts
    const checkPendingUrl = async () => {
      if (!isElectron()) {
        return null;
      }

      try {
        const url = await window.electron?.deepLink.getPendingUrl();
        if (url) {
          setPendingUrl(url);
        }
      } catch (error) {
        console.error('Failed to get pending deep link URL:', error);
      }
    };

    checkPendingUrl();

    // Listen for new deep link events
    const handleDeepLink = (url: string) => {
      setPendingUrl(url);
    };

    window.electron?.deepLink.onDeepLinkReceived(handleDeepLink);

    return () => {
      window.electron?.deepLink.removeDeepLinkListener(handleDeepLink);
    };
  }, []);

  const consumeUrl = () => {
    if (!isElectron()) {
      return null;
    }

    const url = pendingUrl;
    setPendingUrl(null);

    // Also clear it from the main process
    window.electron?.deepLink.clearPendingUrl();

    return url;
  };

  const clearUrl = () => {
    if (!isElectron()) {
      return null;
    }

    setPendingUrl(null);
    window.electron?.deepLink.clearPendingUrl();
  };

  return {
    pendingUrl,
    consumeUrl,
    clearUrl,
    hasPendingUrl: !!pendingUrl,
  };
}
