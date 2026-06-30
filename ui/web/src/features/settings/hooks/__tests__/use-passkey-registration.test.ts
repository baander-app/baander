import { describe, it, expect, vi, beforeEach } from 'vitest';
import { renderHook, act } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import React from 'react';

// Mock the generated endpoints before importing the hook
vi.mock('@/shared/api-client/gen/endpoints', () => ({
  postPasskeyOptions: vi.fn(),
  postPasskeyRegister: vi.fn(),
}));

// Import after mocks
import { usePasskeyRegistration } from '../use-passkey-registration';
import { postPasskeyOptions, postPasskeyRegister } from '@/shared/api-client/gen/endpoints';

const mockPostPasskeyOptions = vi.mocked(postPasskeyOptions);
const mockPostPasskeyRegister = vi.mocked(postPasskeyRegister);

function createWrapper() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return function Wrapper({ children }: { children: React.ReactNode }) {
    return React.createElement(QueryClientProvider, { client: qc }, children)
  }
}

const VALID_OPTIONS_RESPONSE = {
  data: {
    challengeKey: 'test-challenge-key',
    options: {
      challenge: btoa('challenge-bytes'),
      rp: { name: 'Test RP' },
      user: {
        id: btoa('user-id'),
        name: 'test@example.com',
        displayName: 'Test User',
      },
      pubKeyCredParams: [{ type: 'public-key', alg: -7 }],
      excludeCredentials: [],
    },
  },
};

function createMockCredential() {
  const rawId = new ArrayBuffer(4);
  new Uint8Array(rawId).set([1, 2, 3, 4]);

  return {
    id: 'mock-credential-id',
    rawId,
    type: 'public-key' as const,
    response: {
      attestationObject: new ArrayBuffer(8),
      clientDataJSON: new ArrayBuffer(8),
    } as AuthenticatorAttestationResponse,
  } as unknown as PublicKeyCredential;
}

describe('usePasskeyRegistration', () => {
  beforeEach(() => {
    vi.clearAllMocks();

    // Default: no WebAuthn API in jsdom
    vi.stubGlobal('navigator', {
      credentials: {
        create: vi.fn(),
      },
    });
  });

  it('starts with no loading, error, or success state', () => {
    const { result } = renderHook(() => usePasskeyRegistration(), { wrapper: createWrapper() });
    expect(result.current.loading).toBe(false);
    expect(result.current.error).toBeNull();
    expect(result.current.success).toBe(false);
  });

  it('registers a passkey successfully', async () => {
    mockPostPasskeyOptions.mockResolvedValue(VALID_OPTIONS_RESPONSE as never);
    mockPostPasskeyRegister.mockResolvedValue({} as never);
    vi.mocked(navigator.credentials.create).mockResolvedValue(createMockCredential());

    const { result } = renderHook(() => usePasskeyRegistration(), { wrapper: createWrapper() });

    await act(async () => {
      await result.current.register();
    });

    expect(result.current.loading).toBe(false);
    expect(result.current.success).toBe(true);
    expect(result.current.error).toBeNull();
    expect(mockPostPasskeyOptions).toHaveBeenCalledWith({ method: 'POST' });
    expect(mockPostPasskeyRegister).toHaveBeenCalledWith(
      expect.objectContaining({
        challengeKey: 'test-challenge-key',
        name: 'Passkey',
      }),
    );
  });

  it('handles user cancellation (NotAllowedError)', async () => {
    mockPostPasskeyOptions.mockResolvedValue(VALID_OPTIONS_RESPONSE as never);
    vi.mocked(navigator.credentials.create).mockRejectedValue(
      new DOMException('User cancelled', 'NotAllowedError'),
    );

    const { result } = renderHook(() => usePasskeyRegistration(), { wrapper: createWrapper() });

    await act(async () => {
      await result.current.register();
    });

    expect(result.current.loading).toBe(false);
    expect(result.current.error).toBeNull();
    expect(result.current.success).toBe(false);
  });

  it('handles null credential from WebAuthn', async () => {
    mockPostPasskeyOptions.mockResolvedValue(VALID_OPTIONS_RESPONSE as never);
    vi.mocked(navigator.credentials.create).mockResolvedValue(null);

    const { result } = renderHook(() => usePasskeyRegistration(), { wrapper: createWrapper() });

    await act(async () => {
      await result.current.register();
    });

    expect(result.current.loading).toBe(false);
    expect(result.current.error).toBe('Passkey creation was cancelled. Please try again.');
    expect(mockPostPasskeyRegister).not.toHaveBeenCalled();
  });

  it('handles API error during options fetch', async () => {
    mockPostPasskeyOptions.mockRejectedValue(new Error('Network error'));

    const { result } = renderHook(() => usePasskeyRegistration(), { wrapper: createWrapper() });

    await act(async () => {
      await result.current.register();
    });

    expect(result.current.loading).toBe(false);
    expect(result.current.error).toBe('Network error');
  });

  it('handles API error during registration', async () => {
    mockPostPasskeyOptions.mockResolvedValue(VALID_OPTIONS_RESPONSE as never);
    vi.mocked(navigator.credentials.create).mockResolvedValue(createMockCredential());
    mockPostPasskeyRegister.mockRejectedValue(new Error('Registration failed'));

    const { result } = renderHook(() => usePasskeyRegistration(), { wrapper: createWrapper() });

    await act(async () => {
      await result.current.register();
    });

    expect(result.current.loading).toBe(false);
    expect(result.current.error).toBe('Registration failed');
    expect(result.current.success).toBe(false);
  });

  it('handles non-Error thrown values', async () => {
    mockPostPasskeyOptions.mockRejectedValue('string error' as never);

    const { result } = renderHook(() => usePasskeyRegistration(), { wrapper: createWrapper() });

    await act(async () => {
      await result.current.register();
    });

    expect(result.current.error).toBe('Failed to register passkey');
  });
});
