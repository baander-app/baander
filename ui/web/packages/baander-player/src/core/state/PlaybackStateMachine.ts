/**
 * @module core/state/PlaybackStateMachine
 * @description Strict state machine governing playback transitions.
 *
 * States: idle → loading → buffering → ready → playing ⇄ paused → ended
 *         ↘ seeking (can enter from playing/paused/buffering)
 *         ↘ error (can enter from any state)
 *
 * All transitions are validated. Invalid transitions throw.
 * The state machine drives the UI layer and coordinates buffer/transport/ABR modules.
 */

import type { PlaybackState, PlaybackError } from '../../types';

// ---------------------------------------------------------------------------
// Transition Table
// ---------------------------------------------------------------------------

type TransitionMap = Record<PlaybackState, PlaybackState[]>;

const VALID_TRANSITIONS: TransitionMap = {
  idle:       ['loading', 'error'],
  loading:    ['buffering', 'error', 'idle'],
  buffering:  ['ready', 'playing', 'seeking', 'error', 'idle'],
  ready:      ['playing', 'seeking', 'error', 'idle'],
  playing:    ['paused', 'buffering', 'seeking', 'ended', 'error', 'idle'],
  paused:     ['playing', 'seeking', 'error', 'idle'],
  seeking:    ['playing', 'paused', 'buffering', 'error', 'idle'],
  ended:      ['loading', 'idle', 'error'],
  error:      ['loading', 'idle'],
};

export interface StateMachineEvents {
  onStateChange: (from: PlaybackState, to: PlaybackState) => void;
  onError: (error: PlaybackError) => void;
}

/**
 * PlaybackStateMachine — enforces valid state transitions and notifies listeners.
 *
 * Usage:
 * ```ts
 * const sm = new PlaybackStateMachine(events);
 * sm.transition('loading');  // idle → loading ✓
 * sm.transition('playing');  // loading → playing ✗ (would throw)
 * sm.transition('buffering'); // loading → buffering ✓
 * ```
 */
export class PlaybackStateMachine {
  private state: PlaybackState = 'idle';
  private error: PlaybackError | null = null;

  constructor(
    private readonly events: StateMachineEvents,
  ) {}

  /** Get current state. */
  getState(): PlaybackState {
    return this.state;
  }

  /** Get current error (if in error state). */
  getError(): PlaybackError | null {
    return this.error;
  }

  /** Attempt a state transition. Throws if the transition is invalid. */
  transition(newState: PlaybackState): void {
    if (newState === this.state) return;

    const allowed = VALID_TRANSITIONS[this.state];
    if (!allowed?.includes(newState)) {
      throw new Error(
        `Invalid playback state transition: ${this.state} → ${newState}. ` +
        `Allowed: [${allowed?.join(', ') ?? 'none'}]`,
      );
    }

    const from = this.state;
    this.state = newState;
    this.events.onStateChange(from, newState);
  }

  /** Transition to error state. */
  setError(error: PlaybackError): void {
    this.error = error;
    try {
      this.transition('error');
    } catch {
      // If already in a state that can't transition to error, force it.
      // Save the old state BEFORE overwriting so onStateChange gets correct from/to.
      const from = this.state;
      this.state = 'error';
      this.events.onStateChange(from, 'error');
    }
    this.events.onError(error);
  }

  /** Reset to idle. */
  reset(): void {
    const from = this.state;
    this.state = 'idle';
    this.error = null;
    if (from !== 'idle') {
      this.events.onStateChange(from, 'idle');
    }
  }

  /** Check if a transition is valid. */
  canTransitionTo(state: PlaybackState): boolean {
    return VALID_TRANSITIONS[this.state]?.includes(state) ?? false;
  }

  /** Check if currently playing. */
  get isPlaying(): boolean {
    return this.state === 'playing';
  }

  /** Check if buffering. */
  get isBuffering(): boolean {
    return this.state === 'buffering';
  }

  /** Check if an error occurred. */
  get hasError(): boolean {
    return this.state === 'error';
  }
}
