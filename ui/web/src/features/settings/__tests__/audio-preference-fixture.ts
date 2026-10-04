import type { SettingsApplyEqPayload } from '../settings-actions'

export function audioPreferenceFixture(): SettingsApplyEqPayload {
  return {
    enabled: true,
    bands: Array.from({ length: 10 }, (_, index) => ({ gain: index % 2 === 0 ? 3 : -4, q: index % 2 === 0 ? 1.1 : 0.9 })),
    preset: 'ROCK', visualizerMode: 'spectrogram', compressionEnabled: true,
    compressorThreshold: -32, compressorRatio: 6, compressorKnee: 12,
    compressorAttack: 7, compressorRelease: 180, masterGain: -3,
    normalizationEnabled: true, targetLufs: -18, stereoEnabled: true,
    stereoWidth: 1.4, stereoMode: 'side', crossfeedEnabled: true,
    crossfeedPreset: 'heavy', loudnessContourEnabled: true,
    chainOrder: ['stereo', 'eq', 'compressor', 'crossfeed', 'loudness', 'masterGain'],
  }
}
