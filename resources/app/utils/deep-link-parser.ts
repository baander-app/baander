export interface DeepLinkRoute {
  type: 'song' | 'album' | 'playlist' | 'artist' | 'unknown';
  id?: string;
  action?: string;
  params?: Record<string, string>;
}

export function parseDeepLinkUrl(url: string): DeepLinkRoute | null {
  try {
    const parsedUrl = new URL(url);

    // Ensure it's a baander:// URL
    if (parsedUrl.protocol !== 'baander:') {
      return null;
    }

    const path = parsedUrl.pathname;
    const searchParams = Object.fromEntries(parsedUrl.searchParams.entries());

    // Handle different deep link paths
    switch (path) {
      case '/song': {
        const id = parsedUrl.searchParams.get('id');
        const action = parsedUrl.searchParams.get('action') || 'view';
        return {
          type: 'song',
          id: id || undefined,
          action,
          params: searchParams,
        };
      }

      case '/album': {
        const id = parsedUrl.searchParams.get('id');
        const action = parsedUrl.searchParams.get('action') || 'view';
        return {
          type: 'album',
          id: id || undefined,
          action,
          params: searchParams,
        };
      }

      case '/playlist': {
        const id = parsedUrl.searchParams.get('id');
        const action = parsedUrl.searchParams.get('action') || 'view';
        return {
          type: 'playlist',
          id: id || undefined,
          action,
          params: searchParams,
        };
      }

      case '/artist': {
        const id = parsedUrl.searchParams.get('id');
        const action = parsedUrl.searchParams.get('action') || 'view';
        return {
          type: 'artist',
          id: id || undefined,
          action,
          params: searchParams,
        };
      }

      default: {
        return {
          type: 'unknown',
          params: searchParams,
        };
      }
    }
  } catch (error) {
    console.error('Failed to parse deep link URL:', error);
    return null;
  }
}
