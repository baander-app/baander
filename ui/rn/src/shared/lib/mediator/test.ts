/**
 * Mediator tests.
 */

import { register, dispatch, type MediatorAction } from '../types';

describe('Mediator', () => {
  beforeEach(() => {
    // Clear all handlers before each test
    jest.clearAllMocks();
  });

  it('calls registered handler when action is dispatched', async () => {
    const handler = jest.fn();
    register('player:play', handler);

    await dispatch({ type: 'player:play', payload: { trackId: 'track-123' } });

    expect(handler).toHaveBeenCalledTimes(1);
    expect(handler).toHaveBeenCalledWith({ type: 'player:play', payload: { trackId: 'track-123' } });
  });

  it('calls multiple handlers for same action type', async () => {
    const handler1 = jest.fn();
    const handler2 = jest.fn();
    register('player:play', handler1);
    register('player:play', handler2);

    await dispatch({ type: 'player:play', payload: { trackId: 'track-123' } });

    expect(handler1).toHaveBeenCalledTimes(1);
    expect(handler2).toHaveBeenCalledTimes(1);
  });

  it('unsubscribe removes handler', async () => {
    const handler = jest.fn();
    const unsubscribe = register('player:play', handler);
    unsubscribe();

    await dispatch({ type: 'player:play', payload: { trackId: 'track-123' } });

    expect(handler).not.toHaveBeenCalled();
  });

  it('does not call handlers for different action type', async () => {
    const playHandler = jest.fn();
    const pauseHandler = jest.fn();
    register('player:play', playHandler);
    register('player:pause', pauseHandler);

    await dispatch({ type: 'player:pause' });

    expect(playHandler).not.toHaveBeenCalled();
    expect(pauseHandler).toHaveBeenCalledTimes(1);
  });

  it('handles async handlers', async () => {
    const handler = jest.fn(async () => {
      await new Promise((resolve) => setTimeout(resolve, 10));
    });
    register('player:play', handler);

    const start = Date.now();
    await dispatch({ type: 'player:play', payload: { trackId: 'track-123' } });
    const elapsed = Date.now() - start;

    expect(handler).toHaveBeenCalled();
    expect(elapsed).toBeGreaterThanOrEqual(10);
  });

  it('catches handler errors and logs them', async () => {
    const consoleErrorSpy = jest.spyOn(console, 'error').mockImplementation();
    const handler = jest.fn(() => {
      throw new Error('Handler error');
    });
    register('player:play', handler);

    // Should not throw
    await dispatch({ type: 'player:play', payload: { trackId: 'track-123' } });

    expect(consoleErrorSpy).toHaveBeenCalled();
    consoleErrorSpy.mockRestore();
  });
});
