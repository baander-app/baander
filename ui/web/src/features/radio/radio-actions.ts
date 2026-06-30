import type { RadioStation } from './api/radio-api'

/**
 * Radio context action definitions.
 * Dispatched by radio when station state changes.
 */
export const RADIO_ACTIONS = {
  STARTED: 'radio:started',
  STOPPED: 'radio:stopped',
} as const

export interface RadioStartedPayload { station: RadioStation }
