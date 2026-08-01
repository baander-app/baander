import { describe, it, expect } from 'vitest';
import { PlaybackStateMachine } from '../../src/core/state/PlaybackStateMachine';

describe('PlaybackStateMachine', () => {
  it('should start in idle state', () => {
    const sm = new PlaybackStateMachine({
      onStateChange: () => {},
      onError: () => {},
    });
    expect(sm.getState()).toBe('idle');
  });

  it('should allow idle → loading transition', () => {
    const transitions: Array<[string, string]> = [];
    const sm = new PlaybackStateMachine({
      onStateChange: (from, to) => transitions.push([from, to]),
      onError: () => {},
    });

    sm.transition('loading');
    expect(sm.getState()).toBe('loading');
    expect(transitions).toHaveLength(1);
    expect(transitions[0]).toEqual(['idle', 'loading']);
  });

  it('should reject invalid transitions', () => {
    const sm = new PlaybackStateMachine({
      onStateChange: () => {},
      onError: () => {},
    });

    // idle → playing is invalid
    expect(() => sm.transition('playing')).toThrow('Invalid playback state transition');
    expect(sm.getState()).toBe('idle');
  });

  it('should allow full happy path: idle → loading → buffering → ready → playing', () => {
    const sm = new PlaybackStateMachine({
      onStateChange: () => {},
      onError: () => {},
    });

    sm.transition('loading');
    sm.transition('buffering');
    sm.transition('ready');
    sm.transition('playing');
    expect(sm.getState()).toBe('playing');
  });

  it('should allow playing → paused → playing toggle', () => {
    const sm = new PlaybackStateMachine({
      onStateChange: () => {},
      onError: () => {},
    });

    sm.transition('loading');
    sm.transition('buffering');
    sm.transition('ready');
    sm.transition('playing');
    sm.transition('paused');
    expect(sm.getState()).toBe('paused');
    sm.transition('playing');
    expect(sm.getState()).toBe('playing');
  });

  it('should allow seeking from playing and paused', () => {
    const sm = new PlaybackStateMachine({
      onStateChange: () => {},
      onError: () => {},
    });

    // From playing
    sm.transition('loading');
    sm.transition('buffering');
    sm.transition('ready');
    sm.transition('playing');
    sm.transition('seeking');
    sm.transition('playing');

    // From paused
    sm.transition('paused');
    sm.transition('seeking');
    sm.transition('paused');
  });

  it('should handle error from any state', () => {
    const sm = new PlaybackStateMachine({
      onStateChange: () => {},
      onError: () => {},
    });

    sm.transition('loading');
    sm.setError({ code: 'test', message: 'test error', fatal: true });
    expect(sm.getState()).toBe('error');
    expect(sm.hasError).toBe(true);
  });

  it('should allow reset to idle', () => {
    const sm = new PlaybackStateMachine({
      onStateChange: () => {},
      onError: () => {},
    });

    sm.transition('loading');
    sm.reset();
    expect(sm.getState()).toBe('idle');
    expect(sm.hasError).toBe(false);
  });

  it('should report canTransitionTo correctly', () => {
    const sm = new PlaybackStateMachine({
      onStateChange: () => {},
      onError: () => {},
    });

    expect(sm.canTransitionTo('loading')).toBe(true);
    expect(sm.canTransitionTo('playing')).toBe(false);
  });
});
