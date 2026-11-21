import type { SubtitlePreference } from './subtitle-selection'

export interface PresentationChoice {
  id: string
  label: string
}

export interface AudioPresentation {
  audioStreamIndex: number | null
  audioOffsetMs: number
}

export interface VideoPresentationCommands {
  setQuality(id: string): void
  setSubtitle(id: SubtitlePreference): void
  setSubtitleOffset(ms: number): void
  setAudio(index: number | null, offsetMs: number): Promise<void>
}

export interface VideoPresentationSettings extends AudioPresentation {
  qualities: PresentationChoice[]
  quality: string
  currentQuality: string | null
  subtitles: PresentationChoice[]
  subtitle: SubtitlePreference
  subtitleOffsetMs: number
  pendingAudio: AudioPresentation | null
  bufferedUntil: number
}

export const initialVideoSettings = (): VideoPresentationSettings => ({
  qualities: [],
  quality: 'auto',
  currentQuality: null,
  subtitles: [],
  subtitle: 'off',
  subtitleOffsetMs: 0,
  audioStreamIndex: null,
  audioOffsetMs: 0,
  pendingAudio: null,
  bufferedUntil: 0
})

export function assertTimingOffset(ms: number) {
  if (!Number.isInteger(ms) || Math.abs(ms) > 10000) {
    throw new Error(
      'Timing correction must be an integer between -10000 and 10000 ms.'
    )
  }
}
