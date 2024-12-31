import { useEffect, useState } from 'react';

export function useIsOnline() {
  const [isOnline, setIsOnline] = useState<boolean>(false);

  const _isOnline = () => {
    setIsOnline(true);
  };

  const _isOffline = () => {
    setIsOnline(false);
  };

  useEffect(() => {
    window.addEventListener('online', _isOnline);
    window.addEventListener('offline', _isOffline);

    return () => {
      window.removeEventListener('online', _isOnline);
      window.removeEventListener('offline', _isOffline);
    };
  });

  return isOnline;
}