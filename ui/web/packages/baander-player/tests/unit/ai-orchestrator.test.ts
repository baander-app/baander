import { afterEach, describe, expect, it, vi } from 'vitest';
import { AIOrchestrator } from '../../src/ai/AIOrchestrator';
import { DEFAULT_CONFIG } from '../../src/types';

function createOrchestrator(): AIOrchestrator {
  const orchestrator = new AIOrchestrator(
    {
      sampleIntervalSec: 5,
      minConfidence: 0.8,
      maxHighlights: 10,
      useWebNN: false,
      modelUrl: 'https://baander.app/models/scene-classifier.onnx',
    },
    {
      ...DEFAULT_CONFIG,
      baseUrl: 'https://baander.app',
      customHeaders: {
        Authorization: 'DPoP test-token',
        DPoP: 'test-proof',
        'X-Baander-Client': 'embedded-player',
      },
    },
    {
      onClassification: vi.fn(),
      onHighlight: vi.fn(),
      onContentHint: vi.fn(),
      onModeChange: vi.fn(),
      onError: vi.fn(),
    },
  );
  orchestrator.setVideoId('videoPublicIdentity01');
  return orchestrator;
}

describe('AIOrchestrator remixMoment', () => {
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('returns the created session UUID from the direct resource response', async () => {
    const sessionId = 'transcodePublicIdent1';
    const fetchMock = vi.fn().mockResolvedValue(new Response(
      JSON.stringify({ publicId: sessionId }),
      { status: 201, headers: { 'Content-Type': 'application/json' } },
    ));
    vi.stubGlobal('fetch', fetchMock);

    await expect(createOrchestrator().remixMoment({
      startTime: 10,
      endTime: 20,
      qualityTier: '1080p',
    })).resolves.toBe(sessionId);

    expect(fetchMock).toHaveBeenCalledExactlyOnceWith(
      'https://baander.app/api/transcode/sessions/',
      {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Authorization: 'DPoP test-token',
          DPoP: 'test-proof',
          'X-Baander-Client': 'embedded-player',
        },
        body: JSON.stringify({
          videoId: 'videoPublicIdentity01',
          qualityTier: '1080p',
          audioProfile: 'streaming_stereo',
          priority: 'normal',
        }),
      },
    );
  });

  it('reports HTTP failure status before trying to read a session response', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(
      'Unavailable',
      { status: 503, statusText: 'Service Unavailable' },
    )));

    await expect(createOrchestrator().remixMoment({
      startTime: 10,
      endTime: 20,
      qualityTier: '1080p',
    })).rejects.toThrow('Remix failed: 503 Service Unavailable');
  });
});
