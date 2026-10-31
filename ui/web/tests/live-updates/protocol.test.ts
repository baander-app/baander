import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';
import { LIVE_FAMILIES, parseLiveMessage, liveSubscription } from '@/shared/live/protocol';

const fixtures = JSON.parse(readFileSync(resolve(process.cwd(), '../../tests/Fixtures/LiveUpdates/protocol.json'), 'utf8'));

describe('live update protocol', () => {
  it('uses the same finite families as the server', () => {
    expect(LIVE_FAMILIES).toEqual(fixtures.families);
  });

  it('accepts the shared valid envelopes', () => {
    for (const frame of fixtures.valid) expect(parseLiveMessage(frame)).toEqual(frame);
  });

  it('rejects malformed or unbounded envelopes', () => {
    for (const frame of fixtures.invalid) expect(parseLiveMessage(frame)).toBeNull();
    expect(parseLiveMessage({ ...fixtures.valid[0], families: Array(10000).fill('catalog') })).toBeNull();
  });

  it('does not let subscribers select audiences', () => {
    expect(liveSubscription()).toEqual({ type: 'live.subscribe', version: 1 });
  });
});
