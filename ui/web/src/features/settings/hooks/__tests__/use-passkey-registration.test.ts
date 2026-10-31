import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
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
import { advanceAuthorization, synchronizeOwnership } from '@/shared/live/ownership';

const mockPostPasskeyOptions = vi.mocked(postPasskeyOptions);
const mockPostPasskeyRegister = vi.mocked(postPasskeyRegister);

function createWrapper() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return function Wrapper({ children }: { children: React.ReactNode }) {
    return React.createElement(QueryClientProvider, { client: qc }, children)
  }
}

const USER_PUBLIC_ID = 'V1StGXR8_Z5jdHi6B-myT';

const VALID_OPTIONS_RESPONSE = {
  data: {
    challengeKey: 'test-challenge-key',
    options: {
      challenge: btoa('challenge-bytes'),
      rp: { name: 'Test RP' },
      user: {
        id: btoa(USER_PUBLIC_ID),
        name: 'test@baander.app',
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
    const creationOptions = vi.mocked(navigator.credentials.create).mock.calls[0][0]?.publicKey;
    expect(Array.from(new Uint8Array(creationOptions!.user.id as ArrayBuffer))).toEqual(
      Array.from(USER_PUBLIC_ID, (character) => character.charCodeAt(0)),
    );
    expect(mockPostPasskeyRegister).toHaveBeenCalledWith(
      expect.objectContaining({
        challengeKey: 'test-challenge-key',
        name: 'Passkey',
        response: expect.objectContaining({
          attestationObject: btoa('\0'.repeat(8)),
          clientDataJSON: btoa('\0'.repeat(8)),
        }),
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

function deferred<T>() {
  let resolve!: (value: T) => void;
  let reject!: (error: Error) => void;
  const promise = new Promise<T>((done, fail) => { resolve = done; reject = fail; });
  return { promise, resolve, reject };
}

describe('passkey registration attempt ownership', () => {
  beforeEach(() => {
    vi.resetAllMocks();
    synchronizeOwnership('passkey-registration');
    vi.stubGlobal('navigator', { credentials: { create: vi.fn().mockResolvedValue(createMockCredential()) } });
    mockPostPasskeyOptions.mockResolvedValue(VALID_OPTIONS_RESPONSE as never);
    mockPostPasskeyRegister.mockResolvedValue({} as never);
  });

  afterEach(() => {
    synchronizeOwnership(null);
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
  });

  it.each(['options', 'credential', 'registration'] as const)(
    'releases loading after authorization changes during %s', async stage => {
      const pending = deferred<unknown>();
      if (stage === 'options') mockPostPasskeyOptions.mockReturnValueOnce(pending.promise as never);
      if (stage === 'credential') vi.mocked(navigator.credentials.create).mockReturnValueOnce(pending.promise as Promise<Credential | null>);
      if (stage === 'registration') mockPostPasskeyRegister.mockReturnValueOnce(pending.promise as never);
      const { result } = renderHook(() => usePasskeyRegistration(), { wrapper: createWrapper() });
      let attempt!: Promise<void>;
      await act(async () => { attempt = result.current.register(); });
      expect(result.current.loading).toBe(true);

      await act(async () => {
        advanceAuthorization();
        pending.resolve(stage === 'options' ? VALID_OPTIONS_RESPONSE : stage === 'credential' ? createMockCredential() : {});
        await attempt;
      });

      expect(result.current.loading).toBe(false);
      expect(result.current.error).toBeNull();
      expect(result.current.success).toBe(false);
      expect(mockPostPasskeyRegister).toHaveBeenCalledTimes(stage === 'registration' ? 1 : 0);
      if (stage === 'options') expect(navigator.credentials.create).not.toHaveBeenCalled();
      await act(async () => { await result.current.register(); });
      expect(result.current.success).toBe(true);
    },
  );

  it.each(['options', 'credential', 'registration'] as const)(
    'releases loading after an obsolete %s rejects', async stage => {
      const pending = deferred<unknown>();
      if (stage === 'options') mockPostPasskeyOptions.mockReturnValueOnce(pending.promise as never);
      if (stage === 'credential') vi.mocked(navigator.credentials.create).mockReturnValueOnce(pending.promise as Promise<Credential | null>);
      if (stage === 'registration') mockPostPasskeyRegister.mockReturnValueOnce(pending.promise as never);
      const { result } = renderHook(() => usePasskeyRegistration(), { wrapper: createWrapper() });
      let attempt!: Promise<void>;
      await act(async () => { attempt = result.current.register(); });
      await act(async () => {
        advanceAuthorization();
        pending.reject(new Error('Obsolete registration failure'));
        await attempt;
      });
      expect(result.current).toMatchObject({ loading: false, error: null, success: false });
    },
  );

  it('keeps the newer attempt pending when a superseded attempt finishes', async () => {
    const first = deferred<unknown>();
    const second = deferred<unknown>();
    mockPostPasskeyOptions.mockReturnValueOnce(first.promise as never).mockReturnValueOnce(second.promise as never);
    const { result } = renderHook(() => usePasskeyRegistration(), { wrapper: createWrapper() });
    let firstAttempt!: Promise<void>;
    let secondAttempt!: Promise<void>;
    await act(async () => {
      firstAttempt = result.current.register('First');
      secondAttempt = result.current.register('Second');
    });
    await act(async () => { first.resolve(VALID_OPTIONS_RESPONSE); await firstAttempt; });
    expect(result.current).toMatchObject({ loading: true, error: null, success: false });
    expect(navigator.credentials.create).not.toHaveBeenCalled();
    await act(async () => { second.resolve(VALID_OPTIONS_RESPONSE); await secondAttempt; });
    expect(result.current).toMatchObject({ loading: false, error: null, success: true });
    expect(mockPostPasskeyRegister).toHaveBeenCalledExactlyOnceWith(expect.objectContaining({ name: 'Second' }));
  });

  it.each(['options', 'credential', 'registration'] as const)(
    'ignores a %s completion after unmount', async stage => {
      const invalidateQueries = vi.spyOn(QueryClient.prototype, 'invalidateQueries');
      const pending = deferred<unknown>();
      if (stage === 'options') mockPostPasskeyOptions.mockReturnValueOnce(pending.promise as never);
      if (stage === 'credential') vi.mocked(navigator.credentials.create).mockReturnValueOnce(pending.promise as Promise<Credential | null>);
      if (stage === 'registration') mockPostPasskeyRegister.mockReturnValueOnce(pending.promise as never);
      const { result, unmount } = renderHook(() => usePasskeyRegistration(), { wrapper: createWrapper() });
      let attempt!: Promise<void>;
      await act(async () => { attempt = result.current.register(); });
      unmount();
      await act(async () => {
        pending.resolve(stage === 'options' ? VALID_OPTIONS_RESPONSE : stage === 'credential' ? createMockCredential() : {});
        await attempt;
      });
      expect(mockPostPasskeyRegister).toHaveBeenCalledTimes(stage === 'registration' ? 1 : 0);
      expect(result.current.success).toBe(false);
      expect(invalidateQueries).not.toHaveBeenCalled();
      if (stage === 'options') expect(navigator.credentials.create).not.toHaveBeenCalled();
    },
  );
});
