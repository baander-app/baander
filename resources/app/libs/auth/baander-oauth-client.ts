// OAuth client for SPA
class BaanderOAuthClient {
  private clientId: string;
  private authEndpoint?: string;
  private tokenEndpoint?: string;

  constructor() {
    this.clientId = window.oauthConfig?.client_id;
    this.authEndpoint = window.oauthConfig?.authorization_endpoint;
    this.tokenEndpoint = window.oauthConfig?.token_endpoint;
  }

  // Generate PKCE challenge
  async generateCodeChallenge() {
    const codeVerifier = this.generateRandomString(128);
    const encoder = new TextEncoder();
    const data = encoder.encode(codeVerifier);
    const digest = await window.crypto.subtle.digest('SHA-256', data);
    const codeChallenge = btoa(String.fromCharCode(...new Uint8Array(digest)))
      .replace(/\+/g, '-')
      .replace(/\//g, '_')
      .replace(/=/g, '');

    return { codeVerifier, codeChallenge };
  }

  // Login and get authorization code
  async loginAndAuthorize(email: string, password: string) {
    if (!this.clientId) {
      throw new Error('Client ID is not configured');
    }

    if (!email || !password) {
      throw new Error('Email and password are required');
    }

    const { codeVerifier, codeChallenge } = await this.generateCodeChallenge();

    const response = await fetch('/api/oauth/spa/login-authorize', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        email,
        password,
        client_id: this.clientId,
        code_challenge: codeChallenge,
        code_challenge_method: 'S256',
        scope: 'read write stream',
      }),
    });

    const data = await response.json();

    if (data.authorization_code) {
      return this.exchangeCodeForTokens(data.authorization_code, codeVerifier);
    }

    throw new Error(data.message);
  }

  // Exchange authorization code for access token
  async exchangeCodeForTokens(code: string, codeVerifier: string) {
    const response = await fetch('/api/oauth/token', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({
        grant_type: 'authorization_code',
        client_id: this.clientId,
        code: code,
        code_verifier: codeVerifier,
      }),
    });

    const tokens = await response.json();

    if (tokens.access_token) {
      localStorage.setItem('oauth_access_token', tokens.access_token);
      localStorage.setItem('oauth_refresh_token', tokens.refresh_token);
      return tokens;
    }

    throw new Error('Failed to exchange code for tokens');
  }

  generateRandomString(length: number = 16) {
    if (self.crypto && self.crypto.getRandomValues && navigator.userAgent.indexOf('Node.js') === -1) {
      return self.crypto.getRandomValues(new Uint8Array(length)).reduce((str, byte) => str + byte.toString(16).padStart(2, '0'), '');
    }

    const possible = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-._~';
    let text = '';
    for (let i = 0; i < length; i++) {
      text += possible.charAt(Math.floor(Math.random() * possible.length));
    }
    return text;
  }
}
