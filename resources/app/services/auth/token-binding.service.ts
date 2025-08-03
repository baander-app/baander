export class TokenBindingService {
  private sessionId: string | null = null;

  setSessionId(sessionId: string): void {
    this.sessionId = sessionId;
    localStorage.setItem('session_id', sessionId);
  }

  getSessionId(): string | null {
    if (!this.sessionId) {
      this.sessionId = localStorage.getItem('session_id');
    }
    return this.sessionId;
  }

  clearSessionId(): void {
    this.sessionId = null;
    localStorage.removeItem('session_id');
  }

  clear(): void {
    this.clearSessionId();
    // Clear any fingerprint data that might exist from previous versions
    localStorage.removeItem('client_fingerprint');
  }

  /**
   * Simple environment validation - just checks if we have basic browser APIs
   * This is not for security, just for basic functionality validation
   */
  validateEnvironment(): boolean {
    try {
      // Basic sanity checks for browser environment
      return (
        typeof localStorage !== 'undefined' &&
        typeof window !== 'undefined' &&
        typeof document !== 'undefined'
      );
    } catch {
      return false;
    }
  }
}

// Singleton instance
export const tokenBindingService = new TokenBindingService();